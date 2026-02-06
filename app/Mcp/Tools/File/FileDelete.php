<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\ContentIndexing\AutoIndexHelper;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileDelete
{
    public function __construct(
        private FileToolService $fileToolService,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Delete a file with optimistic locking
     *
     * Usage:
     * - Specify path of file to delete
     * - Provide fileQuickHash to verify you're deleting the expected file
     * - Hash verification prevents accidental deletion of modified files
     * - A version snapshot is saved before deletion for recovery
     *
     * Example:
     * - pathAndFilename: "/path/to/file.php"
     * - fileQuickHash: "abc123def456"
     *
     * Returns success confirmation with deleted file details and version_id for recovery
     */
    #[McpTool(name: 'file_delete')]
    public function fileDelete(
        string $pathAndFilename,
        string $fileQuickHash
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Check if file exists
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File does not exist: {$pathAndFilename}");
        }

        // Verify it's a file, not a directory
        if (!is_file($pathAndFilename)) {
            throw new RuntimeException("Path is not a file: {$pathAndFilename}");
        }

        // Verify the quick hash matches (optimistic locking)
        $currentQuickHash = $this->fileToolService->calculateQuickHash($pathAndFilename);
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File hash mismatch. Expected: {$fileQuickHash}, Got: {$currentQuickHash}. " .
                "File may have been modified since you last read it."
            );
        }

        // Get file info before deletion
        $fileInfo = [
            'path' => $pathAndFilename,
            'size' => filesize($pathAndFilename),
            'modified' => filemtime($pathAndFilename),
        ];

        // Read file content BEFORE deletion for version snapshot
        $content = file_get_contents($pathAndFilename);
        if ($content === false) {
            throw new RuntimeException("Failed to read file before deletion: {$pathAndFilename}");
        }
        $lines = explode("\n", $content);
        $lineCount = count($lines);

        // Save version snapshot BEFORE deletion
        $versionId = null;
        if (config('mcp_helpers.file_versioning.enabled', true)) {
            $versionId = $this->fileVersionService->saveVersion(
                pathAndFilename: $pathAndFilename,
                fileQuickHash: $fileQuickHash,
                operationType: 'delete',
                content: $content,
                lineCount: $lineCount,
                operationSummary: [
                    'action' => 'before_delete',
                    'file_size' => $fileInfo['size']
                ]
            );
        }

        // Delete the file
        if (!unlink($pathAndFilename)) {
            throw new RuntimeException("Failed to delete file: {$pathAndFilename}");
        }

        // Remove from search index
        AutoIndexHelper::deleteFromIndex($pathAndFilename);

        return [
            'success' => true,
            'deleted' => true,
            'file' => $pathAndFilename,
            'file_info' => $fileInfo,
            'version_id' => $versionId,
            'recovery_note' => $versionId 
                ? "File can be recovered using file_restore_version with version_id: {$versionId}" 
                : "Versioning disabled, file cannot be recovered",
        ];
    }
}
