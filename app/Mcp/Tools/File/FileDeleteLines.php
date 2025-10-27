<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileDeleteLines
{
    /**
     * @param FileWriteService $fileWriteService
     * @param FileToolService $fileToolService
     */
    public function __construct(
        private FileWriteService $fileWriteService,
        private FileToolService $fileToolService
    ) {}

    /**
     * Delete lines in a range from a file with optimistic locking
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
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read)
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
            throw new RuntimeException(
                "Reference line content mismatch at line {$referenceLineNumber}. " .
                "Expected: " . substr($referenceLineContent, 0, 50) . "... " .
                "Got: " . substr($actualLineContent, 0, 50) . "..."
            );
        }

        // Perform the deletion
        $result = $this->fileWriteService->deleteLines(
            $pathAndFilename,
            $startLine,
            $endLine,
            $fileQuickHash
        );

        return [
            'success' => $result['success'],
            'file' => $pathAndFilename,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'deleted_lines' => $result['deleted_line_count'],
            'total_lines' => $result['line_count'],
            'checksum' => $result['checksum'],
            'file_quick_hash' => $result['file_quick_hash'],
            'new_file' => $this->fileToolService->readFileAndPrepareResults($pathAndFilename),
        ];
    }
}
