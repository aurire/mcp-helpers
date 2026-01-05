<?php

declare(strict_types=1);

namespace App\Service\ContentIndexing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AutoIndexHelper
{
    public function __construct(
        private IndexBuilderService $indexBuilder
    ) {}
    
    /**
     * Attempt to auto-index a file after write operation
     * Non-blocking: logs errors but doesn't fail
     */
    public function indexFileAfterWrite(string $filePath): void
    {
        try {
            if ($this->shouldIndex($filePath)) {
                $this->indexBuilder->indexFile($filePath);
                
                Log::debug('Auto-indexed file after write', [
                    'file' => basename($filePath)
                ]);
            }
        } catch (\Exception $e) {
            // Never fail the write operation due to indexing
            Log::warning('Auto-index after write failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check if file needs reindexing on read (opportunistic reindex)
     * Compares current file hash with indexed hash
     * 
     * @param string $filePath
     * @param string $currentHash Current file hash from file_read
     * @return bool True if reindexed, false if already up-to-date
     */
    public function checkAndReindexOnRead(string $filePath, string $currentHash): bool
    {
        try {
            if (!$this->shouldIndex($filePath)) {
                return false;
            }
            
            // Check if file is already indexed with current hash
            $indexed = DB::table('indexed_files')
                ->where('file_path', $filePath)
                ->first();
            
            // If not indexed at all, or hash doesn't match → reindex
            if (!$indexed || $indexed->file_hash !== $this->calculateIndexHash($filePath)) {
                $this->indexBuilder->indexFile($filePath);
                
                Log::info('Opportunistic reindex on read: hash mismatch detected', [
                    'file' => basename($filePath),
                    'indexed_hash' => $indexed->file_hash ?? 'not_indexed',
                    'current_hash' => $currentHash
                ]);
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            // Never fail the read operation due to indexing
            Log::warning('Opportunistic reindex on read failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Static helper for auto-indexing a file (smart - checks hash first)
     * Only reindexes if the file hash has changed since last index
     * 
     * This is the main method that should be called after file writes
     * It's efficient: ~1ms if unchanged, ~50ms if needs reindex
     */
    public static function autoIndex(string $filePath): void
    {
        try {
            $helper = app(self::class);
            
            if (!$helper->shouldIndex($filePath)) {
                return;
            }
            
            // Calculate current file hash
            $currentHash = $helper->calculateIndexHash($filePath);
            
            // Check if already indexed with current hash
            $indexed = DB::table('indexed_files')
                ->where('file_path', $filePath)
                ->first();
            
            // Only reindex if hash changed or not indexed yet
            if (!$indexed || $indexed->file_hash !== $currentHash) {
                $helper->indexBuilder->indexFile($filePath);
                
                Log::debug('Auto-indexed file (hash changed)', [
                    'file' => basename($filePath),
                    'old_hash' => $indexed->file_hash ?? 'not_indexed',
                    'new_hash' => $currentHash
                ]);
            } else {
                // Hash unchanged - skip reindex
                Log::debug('Skipped auto-index (hash unchanged)', [
                    'file' => basename($filePath)
                ]);
            }
        } catch (\Exception $e) {
            // Never fail operations due to indexing
            Log::warning('Auto-index failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Calculate the same hash that IndexBuilderService uses
     * (xxh3 of filepath:size:mtime)
     */
    private function calculateIndexHash(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        
        $size = filesize($filePath);
        $mtime = filemtime($filePath);
        
        if ($size === false || $mtime === false) {
            throw new \RuntimeException("Failed to get file metadata: {$filePath}");
        }
        
        return hash('xxh3', $filePath . ':' . $size . ':' . $mtime);
    }
    
    /**
     * Determine if a file should be indexed
     */
    private function shouldIndex(string $filePath): bool
    {
        // Don't index temporary files
        if (str_contains($filePath, '/.tmp_')) {
            return false;
        }
        
        // Don't index if it's in excluded directories
        $excludedDirs = [
            'node_modules',
            'vendor',
            '.git',
            'storage/framework',
            'storage/logs',
        ];
        
        foreach ($excludedDirs as $excludedDir) {
            if (str_contains($filePath, '/' . $excludedDir . '/')) {
                return false;
            }
        }
        
        // Don't index binary files (delegate to IndexBuilderService check)
        // Let IndexBuilderService handle this check internally
        
        return true;
    }
}
