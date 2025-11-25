<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileOperationResponseBuilder;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileDeleteLines
{
    public function __construct(
        private FileWriteService $fileWriteService,
        private FileToolService $fileToolService,
        private FileOperationResponseBuilder $responseBuilder,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Delete lines in a range from a file with optimistic locking
     *
     * IMPORTANT FOR OPERATION CHAINING:
     * - This tool returns a new 'file_quick_hash' in the 'FOR_NEXT_OPERATION' field
     * - You MUST use this new hash for subsequent operations on the same file
     * - The response includes 'helpful_context.adjacent_lines' showing lines near the deletion
     * - referenceLineContent must match EXACTLY including all whitespace and indentation
     *
     * Usage:
     * - Read file first with file_read to get file_quick_hash and reference line content
     * - Provide the exact reference line content for verification (startLine or endLine)
     * - LLM must identify which boundary line to use for reference
     *
     * Example:
     * - path: "/path/to/file.php"
     * - startLine: 10 (1-based, first line to delete)
     * - endLine: 12 (1-based, last line to delete - inclusive)
     * - referenceLineContent: "    private string $email;" (content from startLine or endLine for verification)
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read or previous operation)
     * - isStartLine: true (if referenceLineContent is from startLine, false if from endLine)
     */
    #[McpTool(name: 'file_delete_lines')]
    public function fileDeleteLines(
        string $pathAndFilename,
        int $startLine,
        int $endLine,
        string $referenceLineContent,
        string $fileQuickHash,
        bool $isStartLine = true
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Validate line numbers
        if ($startLine < 1 || $endLine < 1) {
            throw new RuntimeException("Line numbers must be >= 1 (1-based indexing)");
        }

        if ($startLine > $endLine) {
            throw new RuntimeException("startLine must be <= endLine");
        }

        // Read file to verify reference line content before deletion
        $contents = file_get_contents($pathAndFilename);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        $lines = explode("\n", $contents);

        // Verify reference line content
        $referenceLineNumber = $isStartLine ? $startLine : $endLine;
        $index = $referenceLineNumber - 1; // Convert to 0-indexed

        if (!isset($lines[$index])) {
            throw new RuntimeException(
                "Line {$referenceLineNumber} not found in file. File has " . count($lines) . " lines."
            );
        }

        $actualLineContent = $lines[$index];
        if ($actualLineContent !== $referenceLineContent) {
            // Use enhanced error response
            return $this->responseBuilder->buildReferenceLineMismatchError(
                $referenceLineNumber,
                $referenceLineContent,
                $actualLineContent,
                $pathAndFilename,
                $lines
            );
        }

        // Perform the deletion
        try {
            $result = $this->fileWriteService->deleteLines(
                $pathAndFilename,
                $startLine,
                $endLine,
                $fileQuickHash
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

        // Get the full file content for context
        $fullFileContent = $this->fileToolService->readFileAndPrepareResults($pathAndFilename);

        // Save version to history
        if (config('mcp_helpers.file_versioning.enabled', true)) {
            $this->fileVersionService->saveVersion(
                pathAndFilename: $pathAndFilename,
                fileQuickHash: $result['file_quick_hash'],
                operationType: 'delete',
                content: implode("\n", $fullFileContent['content']),
                lineCount: $result['line_count'],
                operationSummary: [
                    'lines_affected' => $result['deleted_line_count'],
                    'start_line' => $startLine,
                    'end_line' => $endLine,
                    'deleted_lines_range' => "{$startLine}-{$endLine}"
                ]
            );
        }

        // Build enhanced response
        return $this->responseBuilder->buildDeleteResponse(
            $pathAndFilename,
            $result['file_quick_hash'],
            $startLine,
            $endLine,
            $result['deleted_line_count'],
            $result['line_count'],
            $result['checksum'],
            $fullFileContent
        );
    }
}
