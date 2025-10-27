<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\FileWriteService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileInsertLines
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
     * Insert lines at a specific position in a file with optimistic locking
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
     * - fileQuickHash: "8f2aacddbde03ffd" (from file_read)
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
            // If string contains newlines, split them
            $linesToInsert = explode("\n", $linesToInsert);
        }

        if (empty($linesToInsert)) {
            throw new RuntimeException("linesToInsert cannot be empty");
        }

        // Perform the insertion
        $result = $this->fileWriteService->insertLines(
            $pathAndFilename,
            $lineNumber,
            $referenceLineContent,
            $linesToInsert,
            $fileQuickHash,
            $insertAfter
        );

        return [
            'success' => $result['success'],
            'file' => $pathAndFilename,
            'line_number' => $lineNumber,
            'insert_position' => $insertAfter ? 'after' : 'before',
            'inserted_lines' => $result['inserted_line_count'],
            'total_lines' => $result['line_count'],
            'checksum' => $result['checksum'],
            'file_quick_hash' => $result['file_quick_hash'],
            'new_file' => $this->fileToolService->readFileAndPrepareResults($pathAndFilename),
        ];
    }
}
