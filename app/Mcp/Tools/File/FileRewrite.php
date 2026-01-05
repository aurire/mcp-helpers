<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\ContentIndexing\AutoIndexHelper;
use App\Service\FileOperationResponseBuilder;
use App\Service\FileRewriteService;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileRewrite
{
    public function __construct(
        private FileRewriteService $fileRewriteService,
        private FileToolService $fileToolService,
        private FileOperationResponseBuilder $responseBuilder,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Replace entire file contents atomically with hash-based verification
     *
     * IMPORTANT: This operation replaces the ENTIRE file content in a single atomic operation.
     * It uses optimistic locking via quick_hash to prevent concurrent modification conflicts.
     *
     * Use cases:
     * - Bulk refactoring (rename multiple methods across the file)
     * - Content transformations (format/restructure entire file)
     * - Template replacements
     * - Any change where reading + modifying + writing is simpler than line-by-line edits
     *
     * OPERATION FLOW:
     * 1. Verify fileQuickHash matches current file state
     * 2. Create versioned backup using existing version system
     * 3. Write new content atomically
     * 4. Return new fileQuickHash for subsequent operations
     *
     * Key advantage: Single atomic operation replaces patterns like
     * "create temp file → ask user to manually move"
     *
     * Example usage:
     * 1. Read file with file_read → get current fileQuickHash
     * 2. Modify content as needed (in your code)
     * 3. Call file_rewrite with original hash and new complete content
     * 4. Receive new fileQuickHash for next operation
     *
     * Parameters:
     * - pathAndFilename: Full path to the file
     * - fileQuickHash: Current file's quick hash (from file_read)
     * - content: Complete new file contents (replaces everything)
     *
     * Returns:
     * - success: true on success
     * - file: The file path
     * - new_file_quick_hash: Hash for next operation (use this for chaining)
     * - version_id: Version history ID
     * - checksum: SHA-256 checksum of new content
     *
     * Errors:
     * - Hash mismatch: "File was modified (expected hash X, got Y)"
     * - File not found: "File not found: {path}"
     * - Write fails: Error with details, backup preserved
     */
    #[McpTool(name: 'file_rewrite')]
    public function fileRewrite(
        string $pathAndFilename,
        string $fileQuickHash,
        string $content
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Perform the rewrite through service
        try {
            $result = $this->fileRewriteService->rewriteFile(
                $pathAndFilename,
                $fileQuickHash,
                $content
            );
        } catch (RuntimeException $e) {
            // Check for hash mismatch
            if (str_contains($e->getMessage(), 'File has changed since last read')) {
                if (preg_match('/Expected quick_hash: ([a-f0-9]+), got: ([a-f0-9]+)/', $e->getMessage(), $matches)) {
                    return $this->responseBuilder->buildHashMismatchError(
                        $matches[1],
                        $matches[2],
                        $pathAndFilename
                    );
                }
            }

            throw $e;
        }

        // Save version to history
        if (config('mcp_helpers.file_versioning.enabled', true)) {
            $versionId = $this->fileVersionService->saveVersion(
                pathAndFilename: $pathAndFilename,
                fileQuickHash: $result['new_file_quick_hash'],
                operationType: 'replace',
                content: $content,
                lineCount: $result['metadata']['new_line_count'],
                operationSummary: $result['metadata']
            );
        } else {
            $versionId = null;
        }

        // Auto-index the rewritten file
        AutoIndexHelper::autoIndex($pathAndFilename);

        // Build response with version ID
        return [
            'success' => $result['success'],
            'file' => $result['file'],
            'new_file_quick_hash' => $result['new_file_quick_hash'],
            'version_id' => $versionId,
            'checksum' => $result['checksum'],
            'metadata' => $result['metadata'],
            'FOR_NEXT_OPERATION' => [
                'file_quick_hash' => $result['new_file_quick_hash'],
                'message' => 'Use this hash for the next operation on this file'
            ]
        ];
    }
}
