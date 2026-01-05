<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\ContentIndexing\AutoIndexHelper;
use App\Service\FileOperationResponseBuilder;
use App\Service\FileToolService;
use App\Service\FileVersionService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileInsertLines
{
    public function __construct(
        private FileWriteService $fileWriteService,
        private FileToolService $fileToolService,
        private FileOperationResponseBuilder $responseBuilder,
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Insert lines at a specific position in a file with optimistic locking
     *
     * IMPORTANT FOR OPERATION CHAINING:
     * - This tool returns a new 'file_quick_hash' in the 'FOR_NEXT_OPERATION' field
     * - You MUST use this new hash for subsequent operations on the same file
     * - The response includes 'helpful_context.adjacent_lines' for easy reference line extraction
     * - referenceLineContent must match EXACTLY including all whitespace and indentation
     *
     * Usage:
     * - Read file first with file_read to get file_quick_hash and reference line content
     * - Provide the exact reference line content for verification
     * - Insert before or after the reference line
     * - LLM must provide either linesToInsert as array OR contentString
     *
     * Example:
     * - path: "/path/to/file.php"
     * - lineNumber: 10 (1-based, the reference line)
     * - referenceLineContent: "    private string $name;"
     * - linesToInsert: ["    private string $email;", "    private int $age;"]
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read or previous operation)
     * - insertAfter: true (insert after line 10, before line 11)
     */
    #[McpTool(name: 'file_insert_lines')]
    public function fileInsertLines(
        string $pathAndFilename,
        int $lineNumber,
        string $referenceLineContent,
        array|string $linesToInsert,
        string $fileQuickHash,
        bool $insertAfter = true
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

        // Normalize linesToInsert to array
        if (is_string($linesToInsert)) {
            $linesToInsert = explode("\n", $linesToInsert);
        }

        if (empty($linesToInsert)) {
            throw new RuntimeException("linesToInsert cannot be empty");
        }

        // Perform the insertion
        try {
            $result = $this->fileWriteService->insertLines(
                $pathAndFilename,
                $lineNumber,
                $referenceLineContent,
                $linesToInsert,
                $fileQuickHash,
                $insertAfter
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

            // Check for reference line mismatch
            if (str_contains($e->getMessage(), 'Reference line content mismatch')) {
                // Read file to get lines for error response
                $contents = file_get_contents($pathAndFilename);
                $lines = explode("\n", $contents);
                $actualContent = $lines[$lineNumber - 1] ?? '';

                return $this->responseBuilder->buildReferenceLineMismatchError(
                    $lineNumber,
                    $referenceLineContent,
                    $actualContent,
                    $pathAndFilename,
                    $lines
                );
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
                operationType: 'insert',
                content: implode("\n", $fullFileContent['content']),
                lineCount: $result['line_count'],
                operationSummary: [
                    'lines_affected' => $result['inserted_line_count'],
                    'line_number' => $lineNumber,
                    'insert_position' => $insertAfter ? 'after' : 'before',
                    'inserted_content' => $linesToInsert
                ]
            );
        }

        // Auto-index the modified file
        AutoIndexHelper::autoIndex($pathAndFilename);

        // Build enhanced response
        return $this->responseBuilder->buildInsertResponse(
            $pathAndFilename,
            $result['file_quick_hash'],
            $lineNumber,
            $insertAfter ? 'after' : 'before',
            $result['inserted_line_count'],
            $result['line_count'],
            $result['checksum'],
            $fullFileContent
        );
    }
}
