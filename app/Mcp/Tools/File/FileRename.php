<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\ContentIndexing\AutoIndexHelper;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileRename
{
    public function __construct(
        private FileToolService $fileToolService,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Rename or move a file with optimistic locking
     *
     * Usage:
     * - Specify current path (oldPath) and new path (newPath)
     * - Provide fileQuickHash of the source file to verify you're operating on the expected file
     * - Hash verification prevents accidental rename of modified files
     * - Can be used for simple rename or move to different directory
     * - A version snapshot is saved before renaming for recovery
     *
     * Example:
     * - oldPath: "/path/to/old_name.php"
     * - newPath: "/path/to/new_name.php"
     * - fileQuickHash: "abc123def456"
     *
     * Returns success confirmation with new file details
     */
    #[McpTool(name: 'file_rename')]
    public function fileRename(
        string $oldPath,
        string $newPath,
        string $fileQuickHash
    ): array {
        // Validate both paths are allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $oldPath)) {
            throw new RuntimeException("Access denied: Source path is not within allowed directories");
        }
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $newPath)) {
            throw new RuntimeException("Access denied: Destination path is not within allowed directories");
        }

        // Check if source file exists
        if (!file_exists($oldPath)) {
            throw new RuntimeException("Source file does not exist: {$oldPath}");
        }

        // Verify source is a file, not a directory
        if (!is_file($oldPath)) {
            throw new RuntimeException("Source path is not a file: {$oldPath}");
        }

        // Check if destination already exists
        if (file_exists($newPath)) {
            throw new RuntimeException("Destination file already exists: {$newPath}");
        }

        // Verify the quick hash matches (optimistic locking)
        $currentQuickHash = $this->fileToolService->calculateQuickHash($oldPath);
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File hash mismatch. Expected: {$fileQuickHash}, Got: {$currentQuickHash}. " .
                "File may have been modified since you last read it."
            );
        }

        // Ensure destination directory exists
        $destinationDir = dirname($newPath);
        if (!is_dir($destinationDir)) {
            throw new RuntimeException("Destination directory does not exist: {$destinationDir}");
        }

        // Check if destination directory is writable
        if (!is_writable($destinationDir)) {
            throw new RuntimeException("Destination directory is not writable: {$destinationDir}");
        }

        // Read file content BEFORE rename for version snapshot
        $content = file_get_contents($oldPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file before rename: {$oldPath}");
        }
        $lines = explode("\n", $content);
        $lineCount = count($lines);

        // Save version snapshot BEFORE rename (under old path)
        if (config('mcp_helpers.file_versioning.enabled', true)) {
            $this->fileVersionService->saveVersion(
                pathAndFilename: $oldPath,
                fileQuickHash: $fileQuickHash,
                operationType: 'rename',
                content: $content,
                lineCount: $lineCount,
                operationSummary: [
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'action' => 'before_rename'
                ]
            );
        }

        // Perform the rename (atomic operation on same filesystem)
        if (!rename($oldPath, $newPath)) {
            throw new RuntimeException("Failed to rename file from {$oldPath} to {$newPath}");
        }

        // Update search index: remove old path, add new path
        AutoIndexHelper::deleteFromIndex($oldPath);
        AutoIndexHelper::autoIndex($newPath);

        // Read the renamed file with full metadata
        $renamedFile = $this->fileToolService->readFileAndPrepareResults($newPath);

        // Save version snapshot AFTER rename (under new path)
        if (config('mcp_helpers.file_versioning.enabled', true)) {
            $this->fileVersionService->saveVersion(
                pathAndFilename: $newPath,
                fileQuickHash: $renamedFile['file_quick_hash'],
                operationType: 'rename',
                content: $content,
                lineCount: $lineCount,
                operationSummary: [
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'action' => 'after_rename'
                ]
            );
        }

        return [
            'success' => true,
            'renamed' => true,
            'old_path' => $oldPath,
            'new_path' => $newPath,
            'checksum' => $renamedFile['checksum'],
            'file_quick_hash' => $renamedFile['file_quick_hash'],
            'renamed_file' => $renamedFile,
        ];
    }
}
