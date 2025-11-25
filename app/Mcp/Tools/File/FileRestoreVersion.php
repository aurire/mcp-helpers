<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileOperationResponseBuilder;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileRestoreVersion
{
    public function __construct(
        private FileVersionService $fileVersionService,
        private FileWriteService $fileWriteService,
        private FileToolService $fileToolService,
        private FileOperationResponseBuilder $responseBuilder
    ) {}

    /**
     * Restore a file to a previous version
     *
     * This tool restores a file to a saved version state. The current state
     * is automatically saved before restoring, so you can always go back.
     *
     * IMPORTANT FOR OPERATION CHAINING:
     * - Returns new 'file_quick_hash' in 'FOR_NEXT_OPERATION' field
     * - Use this new hash for subsequent operations on the file
     * - Includes 'helpful_context.adjacent_lines' for reference
     *
     * You must provide:
     * - pathAndFilename: The file to restore
     * - currentFileQuickHash: Current hash (optimistic locking)
     * - ONE of: versionId OR fileQuickHash (which version to restore to)
     *
     * Example:
     * - pathAndFilename: "/path/to/file.php"
     * - versionId: 42 (restore to version #42)
     * - currentFileQuickHash: "8f2aacddbde03ffd" (current file hash)
     *
     * What happens:
     * 1. Current file state is saved as a new version (operation_type: 'restore')
     * 2. File is replaced with content from specified version
     * 3. You can continue chaining operations with the new hash
     * 4. If needed, you can restore back to the state before restore
     */
    #[McpTool(name: 'file_restore_version')]
    public function fileRestoreVersion(
        string $pathAndFilename,
        string $currentFileQuickHash,
        ?int $versionId = null,
        ?string $fileQuickHash = null
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Validate that at least one version identifier is provided
        if ($versionId === null && $fileQuickHash === null) {
            throw new RuntimeException('Must provide either versionId or fileQuickHash');
        }

        // Check if file exists
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        // Save current state before restoring (as backup)
        $currentContent = file_get_contents($pathAndFilename);
        if ($currentContent === false) {
            throw new RuntimeException("Failed to read current file: {$pathAndFilename}");
        }

        $currentLines = explode("\n", $currentContent);
        $this->fileVersionService->saveVersion(
            pathAndFilename: $pathAndFilename,
            fileQuickHash: $currentFileQuickHash,
            operationType: 'restore',
            content: $currentContent,
            lineCount: count($currentLines),
            operationSummary: [
                'action' => 'pre_restore_backup',
                'restoring_to_version_id' => $versionId,
                'restoring_to_hash' => $fileQuickHash,
            ]
        );

        // Get version to restore
        $versionData = $this->fileVersionService->restoreVersion(
            $pathAndFilename,
            $versionId,
            $fileQuickHash
        );

        // Convert restored content to lines
        $restoredLines = explode("\n", $versionData['content']);
        $currentLineCount = count($currentLines);
        
        // Write the restored content using replaceLines (replaces entire file)
        try {
            $writeResult = $this->fileWriteService->replaceLines(
                $pathAndFilename,
                1,
                $currentLineCount,
                $restoredLines,
                $currentFileQuickHash
            );
        } catch (RuntimeException $e) {
            // Check if it's a hash mismatch error
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

        // Get full file content for context
        $fullFileContent = $this->fileToolService->readFileAndPrepareResults($pathAndFilename);

        // Build enhanced success response
        return [
            'success' => true,
            'FOR_NEXT_OPERATION' => [
                'file_quick_hash' => $writeResult['file_quick_hash'],
                'instruction' => '⚠️  CRITICAL: Use this hash for your next operation on this file',
            ],
            'file' => $pathAndFilename,
            'operation_summary' => [
                'type' => 'version_restore',
                'restored_from_version_id' => $versionData['version_id'],
                'restored_line_count' => $versionData['line_count'],
                'original_operation' => $versionData['operation_summary'],
            ],
            'helpful_context' => [
                'tip' => '💡 File restored successfully. Current state was saved before restore.',
                'adjacent_lines' => $this->getAdjacentLines($fullFileContent['content'], 1, 10),
            ],
            'total_lines' => count($fullFileContent['content']),
            'checksum' => $writeResult['checksum'],
            'file_quick_hash' => $writeResult['file_quick_hash'],
            'tips' => [
                'The file has been restored to the selected version',
                'Your previous state was automatically saved before restore',
                'Use file_list_versions to see the complete history including this restore',
                'You can restore back to the pre-restore state if needed',
            ],
        ];
    }

    /**
     * Get adjacent lines for context
     */
    private function getAdjacentLines(array $content, int $centerLine, int $range = 5): array
    {
        $result = [];
        $start = max(1, $centerLine - $range);
        $end = min(count($content), $centerLine + $range);

        for ($i = $start; $i <= $end; $i++) {
            $result[(string)$i] = $content[(string)$i];
        }

        return $result;
    }
}
