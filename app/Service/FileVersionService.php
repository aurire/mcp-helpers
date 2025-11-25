<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FileVersionService
{
    /**
     * Save a new version of a file with deduplication
     */
    public function saveVersion(
        string $pathAndFilename,
        string $fileQuickHash,
        string $operationType,
        string $content,
        int $lineCount,
        ?array $operationSummary = null,
        ?string $userId = null,
        ?string $sessionId = null
    ): ?int {
        // Check if versioning is enabled
        if (!config('mcp_helpers.file_versioning.enabled', true)) {
            return null;
        }

        // Check file size limit
        $fileSize = strlen($content);
        $maxSize = config('mcp_helpers.file_versioning.max_file_size', 5 * 1024 * 1024);
        if ($fileSize > $maxSize) {
            // Skip versioning for files exceeding limit
            return null;
        }

        // Calculate content hash for deduplication
        $contentHash = hash('sha256', $content);

        // Check if this content already exists as the latest version for this file
        $latestVersion = DB::table('file_versions')
            ->where('file_path', $pathAndFilename)
            ->orderBy('created_at', 'desc')
            ->first(['content_hash']);

        if ($latestVersion && $latestVersion->content_hash === $contentHash) {
            // Content hasn't changed, skip saving
            return null;
        }

        // Insert new version
        $versionId = DB::table('file_versions')->insertGetId([
            'file_path' => $pathAndFilename,
            'file_quick_hash' => $fileQuickHash,
            'content_hash' => $contentHash,
            'operation_type' => $operationType,
            'content' => $content,
            'line_count' => $lineCount,
            'file_size' => $fileSize,
            'operation_summary' => $operationSummary ? json_encode($operationSummary) : null,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'created_at' => now(),
        ]);

        return $versionId;
    }

    /**
     * List versions of a file with pagination
     */
    public function listVersions(
        string $pathAndFilename,
        int $limit = 50,
        int $offset = 0
    ): array {
        // Get total count
        $total = DB::table('file_versions')
            ->where('file_path', $pathAndFilename)
            ->count();

        // Get versions
        $versions = DB::table('file_versions')
            ->where('file_path', $pathAndFilename)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get([
                'id',
                'file_quick_hash',
                'content_hash',
                'operation_type',
                'line_count',
                'file_size',
                'operation_summary',
                'user_id',
                'session_id',
                'created_at'
            ]);

        $result = [];
        foreach ($versions as $version) {
            $result[] = [
                'version_id' => $version->id,
                'file_quick_hash' => $version->file_quick_hash,
                'content_hash' => substr($version->content_hash, 0, 12) . '...',
                'operation_type' => $version->operation_type,
                'line_count' => $version->line_count,
                'file_size' => $this->formatFileSize($version->file_size),
                'operation_summary' => $version->operation_summary ? json_decode($version->operation_summary, true) : null,
                'created_at' => $version->created_at,
                'age' => $this->formatAge($version->created_at),
                'user_id' => $version->user_id,
                'session_id' => $version->session_id,
            ];
        }

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'versions' => $result,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Get a specific version by ID or hash
     */
    public function getVersion(?int $versionId = null, ?string $fileQuickHash = null): ?array
    {
        if (!$versionId && !$fileQuickHash) {
            throw new RuntimeException('Must provide either versionId or fileQuickHash');
        }

        $query = DB::table('file_versions');

        if ($versionId) {
            $query->where('id', $versionId);
        } else {
            $query->where('file_quick_hash', $fileQuickHash);
        }

        $version = $query->first();

        if (!$version) {
            return null;
        }

        // Convert content to 1-indexed array like file_read
        $lines = explode("\n", $version->content);
        $contentArray = [];
        foreach ($lines as $index => $line) {
            $contentArray[(string)($index + 1)] = $line;
        }

        return [
            'version_id' => $version->id,
            'file_path' => $version->file_path,
            'file_quick_hash' => $version->file_quick_hash,
            'content_hash' => $version->content_hash,
            'operation_type' => $version->operation_type,
            'content' => $contentArray,
            'line_count' => $version->line_count,
            'file_size' => $this->formatFileSize($version->file_size),
            'operation_summary' => $version->operation_summary ? json_decode($version->operation_summary, true) : null,
            'created_at' => $version->created_at,
            'age' => $this->formatAge($version->created_at),
            'user_id' => $version->user_id,
            'session_id' => $version->session_id,
        ];
    }

    /**
     * Restore a file to a previous version
     */
    public function restoreVersion(
        string $pathAndFilename,
        ?int $versionId = null,
        ?string $fileQuickHash = null
    ): array {
        // Get the version to restore
        $version = $this->getVersion($versionId, $fileQuickHash);

        if (!$version) {
            throw new RuntimeException('Version not found');
        }

        // Verify the file path matches
        if ($version['file_path'] !== $pathAndFilename) {
            throw new RuntimeException('Version belongs to a different file');
        }

        // Convert content array back to string
        $content = implode("\n", $version['content']);

        return [
            'content' => $content,
            'line_count' => $version['line_count'],
            'version_id' => $version['version_id'],
            'operation_summary' => $version['operation_summary'],
        ];
    }

    /**
     * Clean up old versions based on strategy
     */
    public function cleanupVersions(
        ?string $pathAndFilename = null,
        string $strategy = 'keep_last_n',
        ?int $keepCount = null,
        ?int $keepDays = null,
        bool $dryRun = true
    ): array {
        if ($strategy === 'keep_last_n' && $keepCount === null) {
            $keepCount = config('mcp_helpers.file_versioning.keep_versions_per_file', 50);
        }

        if ($strategy === 'keep_days' && $keepDays === null) {
            $keepDays = 30;
        }

        $toDelete = [];

        if ($pathAndFilename) {
            // Cleanup for specific file
            $toDelete = $this->getVersionsToDelete($pathAndFilename, $strategy, $keepCount, $keepDays);
        } else {
            // Cleanup for all files
            $files = DB::table('file_versions')
                ->distinct()
                ->pluck('file_path');

            foreach ($files as $file) {
                $toDelete = array_merge(
                    $toDelete,
                    $this->getVersionsToDelete($file, $strategy, $keepCount, $keepDays)
                );
            }
        }

        $result = [
            'dry_run' => $dryRun,
            'strategy' => $strategy,
            'versions_to_delete' => count($toDelete),
            'versions' => $toDelete,
        ];

        if (!$dryRun && !empty($toDelete)) {
            $versionIds = array_column($toDelete, 'version_id');
            DB::table('file_versions')
                ->whereIn('id', $versionIds)
                ->delete();
            $result['deleted'] = count($versionIds);
        }

        return $result;
    }

    /**
     * Get versions to delete based on strategy
     */
    private function getVersionsToDelete(
        string $pathAndFilename,
        string $strategy,
        ?int $keepCount,
        ?int $keepDays
    ): array {
        $query = DB::table('file_versions')
            ->where('file_path', $pathAndFilename)
            ->orderBy('created_at', 'desc');

        $versions = $query->get(['id', 'created_at', 'operation_type', 'file_size']);

        if ($strategy === 'keep_last_n') {
            // Keep the N most recent versions
            $toDelete = $versions->skip($keepCount);
        } else {
            // keep_days: Keep versions from last N days
            $cutoffDate = Carbon::now()->subDays($keepDays);
            $toDelete = $versions->filter(function ($version) use ($cutoffDate) {
                return Carbon::parse($version->created_at)->lt($cutoffDate);
            });
        }

        return $toDelete->map(function ($version) use ($pathAndFilename) {
            return [
                'version_id' => $version->id,
                'file_path' => $pathAndFilename,
                'created_at' => $version->created_at,
                'age' => $this->formatAge($version->created_at),
                'operation_type' => $version->operation_type,
                'file_size' => $this->formatFileSize($version->file_size),
            ];
        })->values()->toArray();
    }

    /**
     * Format file size in human-readable format
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Format age in human-readable format
     */
    private function formatAge(string $timestamp): string
    {
        $carbon = Carbon::parse($timestamp);
        $now = Carbon::now();

        $diff = $now->diffInSeconds($carbon);

        if ($diff < 60) {
            return $diff . ' seconds ago';
        }

        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';
        }

        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
        }

        if ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
        }

        return $carbon->format('Y-m-d H:i:s');
    }
}
