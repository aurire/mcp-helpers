<?php

declare(strict_types=1);

namespace App\Service;

use Illuminate\Support\Facades\Cache;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CachedDirTree
{
    private const MAX_FILES_TO_CACHE = 50000; // Limit to prevent memory exhaustion
    private const MAX_DIRS_TO_TRACK = 10000; // Limit directory tracking
    private array $files = [];
    private array $dirMTimes = [];
    private string $cacheKey;
    private string $hashKey;
    private bool $limitReached = false; // Track if we hit the limit

    public function __construct(private string $baseDir) {
        $baseDirHash = md5($baseDir);
        $this->cacheKey = "filesearch:{$baseDirHash}:files";
        $this->hashKey = "filesearch:{$baseDirHash}:hashes";
    }

    public function search(string $pattern, ?string $extension = null): array {
        $this->ensureFreshCache();

        $results = [];
        foreach ($this->files as $path => $meta) {
            if ($extension && $meta['ext'] !== $extension) {
                continue;
            }

            if (preg_match($pattern, $path)) {
                $results[] = [
                    'path' => $path,
                    'size' => $meta['size'],
                    'mtime' => $meta['mtime'],
                    'extension' => $meta['ext'],
                    'quick_hash' => $meta['quick_hash'],
                ];
            }
        }

        return $results;
    }

    public function findByExtension(string $extension): array {
        $this->ensureFreshCache();

        $results = [];
        foreach ($this->files as $path => $meta) {
            if ($meta['ext'] === $extension) {
                $results[] = [
                    'path' => $path,
                    'size' => $meta['size'],
                    'mtime' => $meta['mtime'],
                    'extension' => $meta['ext'],
                    'quick_hash' => $meta['quick_hash'],
                ];
            }
        }

        return $results;
    }

    public function all(?callable $filter = null): array {
        $this->ensureFreshCache();

        $results = [];
        foreach ($this->files as $path => $meta) {
            $file = [
                'path' => $path,
                'size' => $meta['size'],
                'mtime' => $meta['mtime'],
                'extension' => $meta['ext'],
                'quick_hash' => $meta['quick_hash'],
            ];

            if (!$filter || $filter($file)) {
                $results[] = $file;
            }
        }

        return $results;
    }

    public function invalidate(): void {
        Cache::forget($this->cacheKey);
        Cache::forget($this->hashKey);
        $this->files = [];
        $this->dirMTimes = [];
    }

    private function ensureFreshCache(): void {
        if (!empty($this->files)) {
            return;
        }

        // Try to load from cache
        $cachedFiles = Cache::get($this->cacheKey);
        $cachedHashes = Cache::get($this->hashKey);

        if ($cachedFiles !== null && $cachedHashes !== null) {
            $this->files = $cachedFiles;
            $this->dirMTimes = $cachedHashes;

            // Quick check: just compare directory mtimes
            if (!$this->hasDirectoryChanges()) {
                return; // No structure changes - we're good!
            }

            // Something changed, find what changed
            $changedDirs = $this->getChangedDirectories();

            if (count($changedDirs) > 10) {
                // Too many changes, just do full rebuild
                $this->fullRebuild();
            } else {
                // Partial rescan of changed directories
                $this->partialRescan($changedDirs);
            }
            return;
        }

        $this->fullRebuild();
    }

    /**
     * Super fast check - just compare directory mtimes
     */
    private function hasDirectoryChanges(): bool {
        foreach ($this->dirMTimes as $dirPath => $cachedMtime) {
            if (!is_dir($dirPath)) {
                return true; // Directory deleted
            }

            clearstatcache(true, $dirPath);
            if (filemtime($dirPath) !== $cachedMtime) {
                return true; // Directory changed
            }
        }

        return false;
    }

    /**
     * Find which directories changed
     */
    private function getChangedDirectories(): array {
        $changed = [];

        // Check all cached directories for changes
        foreach ($this->dirMTimes as $dirPath => $cachedMtime) {
            if (!is_dir($dirPath)) {
                $changed[] = $dirPath;
                continue;
            }

            clearstatcache(true, $dirPath);
            if (filemtime($dirPath) !== $cachedMtime) {
                $changed[] = $dirPath;
            }
        }

        return $changed;
    }

    private function partialRescan(array $changedDirs): void {
        foreach ($changedDirs as $dirPath) {
            // Remove old files from this directory and subdirectories
            $prefix = $dirPath . DIRECTORY_SEPARATOR;
            foreach ($this->files as $filePath => $meta) {
                if (str_starts_with($filePath, $prefix) || dirname($filePath) === $dirPath) {
                    unset($this->files[$filePath]);
                }
            }

            // Remove old directory mtimes for this path and subdirectories
            foreach ($this->dirMTimes as $cachedDir => $mtime) {
                if (str_starts_with($cachedDir, $prefix) || $cachedDir === $dirPath) {
                    unset($this->dirMTimes[$cachedDir]);
                }
            }

            // Rescan this directory (and all subdirectories)
            if (is_dir($dirPath)) {
                $this->scanDirectory($dirPath);
            }
        }

        $this->saveCache();
    }

    private function scanDirectory(string $dirPath): void {
        try {
            $iterator = $this->getFilteredIterator($dirPath);

            foreach ($iterator as $file) {
                // Stop if we've hit the limits
                if (count($this->files) >= self::MAX_FILES_TO_CACHE || count($this->dirMTimes) >= self::MAX_DIRS_TO_TRACK) {
                    $this->limitReached = true;
                    break;
                }

                if ($file->isFile()) {
                    $path = $file->getPathname();
                    $size = $file->getSize();
                    $mtime = $file->getMTime();

                    $this->files[$path] = [
                        'size' => $size,
                        'mtime' => $mtime,
                        'ext' => $file->getExtension(),
                        'quick_hash' => hash('xxh3', $path . ':' . $size . ':' . $mtime),
                    ];
                } elseif ($file->isDir()) {
                    $path = $file->getPathname();
                    clearstatcache(true, $path);
                    $this->dirMTimes[$path] = filemtime($path);
                }
            }
        } catch (\Exception $e) {
            return;
        }
    }

    private function fullRebuild(): void {
        $this->files = [];
        $this->dirMTimes = [];

        $iterator = $this->getFilteredIterator($this->baseDir);

        foreach ($iterator as $file) {
            // Stop if we've hit the limits
            if (count($this->files) >= self::MAX_FILES_TO_CACHE || count($this->dirMTimes) >= self::MAX_DIRS_TO_TRACK) {
                $this->limitReached = true;
                break;
            }

            if ($file->isFile()) {
                $path = $file->getPathname();
                $size = $file->getSize();
                $mtime = $file->getMTime();

                $this->files[$path] = [
                    'size' => $size,
                    'mtime' => $mtime,
                    'ext' => $file->getExtension(),
                    'quick_hash' => hash('xxh3', $path . ':' . $size . ':' . $mtime),
                ];
            } elseif ($file->isDir()) {
                $path = $file->getPathname();
                clearstatcache(true, $path);
                $this->dirMTimes[$path] = filemtime($path);
            }
        }

        $this->saveCache();
    }

    private function saveCache(): void {
        // Don't cache if we hit the limit or if arrays are too large
        if ($this->limitReached) {
            return;
        }

        // Estimate memory usage and skip caching if too large
        // Each file entry is roughly 200 bytes (path + metadata)
        $estimatedMemory = (count($this->files) * 200) + (count($this->dirMTimes) * 150);
        if ($estimatedMemory > 50 * 1024 * 1024) { // 50MB limit
            return; // Skip caching to avoid memory exhaustion
        }

        // Store indefinitely - we manually invalidate when needed
        // Or use a TTL: Cache::put($key, $value, now()->addHours(24));
        Cache::forever($this->cacheKey, $this->files);
        Cache::forever($this->hashKey, $this->dirMTimes);
    }

    private function getFilteredIterator(string $path): RecursiveIteratorIterator {
        $dirIterator = new RecursiveDirectoryIterator(
            $path,
            RecursiveDirectoryIterator::SKIP_DOTS |
            RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        );

        $filterIterator = new RecursiveCallbackFilterIterator(
            $dirIterator,
            function ($current, $key, $iterator) {
                try {
                    return $current->isFile() || $current->isDir();
                } catch (\RuntimeException $e) {
                    return false;
                }
            }
        );

        return new RecursiveIteratorIterator(
            $filterIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );
    }
}
