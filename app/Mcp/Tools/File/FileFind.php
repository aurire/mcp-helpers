<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileFindService;
use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileFind
{
    private array $allowedPaths = [];

    public function __construct(
        private FileFindService $fileFindService,
        private FileToolService $fileToolService
    )
    {
        $this->allowedPaths = $this->fileToolService->getAllowedPaths();
    }

    /**
     * Search for files by filename/path pattern
     *
     * Examples:
     * - baseDir: "/var/www/project"
     * - query: "Controller" - finds files with "Controller" in path
     * - query: "*Service.php" - finds files ending with Service.php
     * - extension: "php" - filter to .php files only
     */
    #[McpTool(name: 'file_find')]
    public function fileFind(
        string $baseDir,
        string $query,
        ?string $extension = null
    ): array
    {
        if (!$this->fileToolService->isPathAllowed($this->allowedPaths, $baseDir)) {
            throw new RuntimeException("Access denied: Base directory is not in allowed paths");
        }

        if (!is_dir($baseDir)) {
            throw new RuntimeException("Base directory not found: {$baseDir}");
        }

        $results = $this->fileFindService->searchByFilename($baseDir, $query, $extension);

        if (isset($results['error'])) {
            throw new RuntimeException($results['error']);
        }

        return [
            'count' => count($results),
            'base_dir' => $baseDir,
            'files' => $results,
        ];
    }

    /**
     * Search for content inside files
     *
     * Examples:
     * - baseDir: "/var/www/project"
     * - contentQuery: "processPayment" - find files containing this text
     * - filePattern: "Controller" - only search in files matching this pattern
     * - extension: "php" - only search .php files
     * - caseInsensitive: true - ignore case when searching
     * - contextLines: 2 - show 2 lines before/after each match
     * - maxResults: 50 - maximum number of files to return
     */
    #[McpTool(name: 'file_search_content')]
    public function searchContent(
        string $baseDir,
        string $contentQuery,
        ?string $filePattern = null,
        ?string $extension = null,
        bool $caseInsensitive = true,
        ?int $contextLines = 2,
        ?int $maxResults = 50
    ): array
    {
        if (!$this->fileToolService->isPathAllowed($this->allowedPaths, $baseDir)) {
            throw new RuntimeException("Access denied: Base directory is not in allowed paths");
        }

        if (!is_dir($baseDir)) {
            throw new RuntimeException("Base directory not found: {$baseDir}");
        }

        $results = $this->fileFindService->searchInFiles(
            $baseDir,
            $contentQuery,
            $filePattern,
            $extension,
            $caseInsensitive,
            $contextLines,
            $maxResults
        );

        if (isset($results['error'])) {
            throw new RuntimeException($results['error']);
        }

        return $results;
    }
}
