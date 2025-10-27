<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileReplaceLine
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
     * Replace a single line in a file with optimistic locking
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
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read)
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
            throw new RuntimeException(
                "Reference line content mismatch at line {$lineNumber}. " .
                "Expected: " . substr($referenceLineContent, 0, 50) . "... " .
                "Got: " . substr($actualLineContent, 0, 50) . "..."
            );
        }

        // Perform the replacement
        $result = $this->fileWriteService->replaceLines(
            $pathAndFilename,
            $lineNumber,
            $lineNumber,
            [$newLineContent],
            $fileQuickHash
        );

        return [
            'success' => $result['success'],
            'file' => $pathAndFilename,
            'line_number' => $lineNumber,
            'old_content' => $referenceLineContent,
            'new_content' => $newLineContent,
            'replaced_lines' => $result['replaced_line_count'],
            'total_lines' => $result['line_count'],
            'checksum' => $result['checksum'],
            'file_quick_hash' => $result['file_quick_hash'],
            'new_file' => $this->fileToolService->readFileAndPrepareResults($pathAndFilename),
        ];
    }
}
