<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileOperationResponseBuilder;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileReplaceLine
{
    public function __construct(
        private FileWriteService $fileWriteService,
        private FileToolService $fileToolService,
        private FileOperationResponseBuilder $responseBuilder,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Replace a single line in a file with optimistic locking
     *
     * IMPORTANT FOR OPERATION CHAINING:
     * - This tool returns a new 'file_quick_hash' in the 'FOR_NEXT_OPERATION' field
     * - You MUST use this new hash for subsequent operations on the same file
     * - The response includes 'helpful_context.adjacent_lines' for easy reference line extraction
     * - referenceLineContent must match EXACTLY including all whitespace and indentation
     *
     * OPERATION CHAINING EXAMPLE:
     * 1. Call file_read → get hash_1
     * 2. Call file_replace_line with hash_1 → returns hash_2 in FOR_NEXT_OPERATION
     * 3. Call file_replace_line with hash_2 → returns hash_3 in FOR_NEXT_OPERATION
     *
     * Usage:
     * - Read file first with file_read to get file_quick_hash and reference line content
     * - Provide the exact reference line content for verification
     * - Replace with a single new line only (multi-line replacements not allowed)
     *
     * Example:
     * - path: "/path/to/file.php"
     * - lineNumber: 15 (1-based, the line to replace)
     * - referenceLineContent: "    private string $oldName;"
     * - newLineContent: "    private string $newName;"
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read or previous operation)
     */
    #[McpTool(name: 'file_replace_line')]
    public function fileReplaceLine(
        string $pathAndFilename,
        int $lineNumber,
        string $referenceLineContent,
        string $newLineContent,
        string $fileQuickHash
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Validate line number
        if ($lineNumber < 1) {
            throw new RuntimeException("Line number must be >= 1 (1-based indexing)");
        }

        // Verify reference line content before replacement
        $contents = file_get_contents($pathAndFilename);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        $lines = explode("\n", $contents);

        // Verify line exists
        $index = $lineNumber - 1; // Convert to 0-indexed
        if (!isset($lines[$index])) {
            throw new RuntimeException(
                "Line {$lineNumber} not found in file. File has " . count($lines) . " lines."
            );
        }

        // Verify reference line content matches
        $actualLineContent = $lines[$index];
        if ($actualLineContent !== $referenceLineContent) {
            // Use enhanced error response
            return $this->responseBuilder->buildReferenceLineMismatchError(
                $lineNumber,
                $referenceLineContent,
                $actualLineContent,
                $pathAndFilename,
                $lines
            );
        }

        // Perform the replacement
        try {
            $result = $this->fileWriteService->replaceLines(
                $pathAndFilename,
                $lineNumber,
                $lineNumber,
                [$newLineContent],
                $fileQuickHash
            );
        } catch (RuntimeException $e) {
            // Check if it's a hash mismatch error
            if (str_contains($e->getMessage(), 'File has changed since last read')) {
                // Extract hashes from error message
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
                operationType: 'replace',
                content: implode("\n", $fullFileContent['content']),
                lineCount: $result['line_count'],
                operationSummary: [
                    'lines_affected' => $result['replaced_line_count'],
                    'line_number' => $lineNumber,
                    'old_content' => $referenceLineContent,
                    'new_content' => $newLineContent
                ]
            );
        }

        // Build enhanced success response
        return $this->responseBuilder->buildSuccessResponse(
            $pathAndFilename,
            'line_replacement',
            $result['file_quick_hash'],
            $lineNumber,
            $referenceLineContent,
            $newLineContent,
            $fullFileContent,
            $result['replaced_line_count'],
            $result['line_count'],
            $result['checksum']
        );
    }
}
