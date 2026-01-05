<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileFindService;
use App\Service\FileToolService;
use App\Service\ContentIndexing\IndexSearchService;
use App\Service\ContentIndexing\IndexBuilderService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;
use Illuminate\Support\Facades\Log;

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
     * Search for files by filename/path pattern with result limit
     *
     * Examples:
     * - baseDir: "/var/www/project"
     * - query: "Controller" - finds files with "Controller" in path
     * - query: "*Service.php" - finds files ending with Service.php
     * - extension: "php" - filter to .php files only
     * - maxResults: 100 - maximum number of files to return (default: 100)
     */
    #[McpTool(name: 'file_find')]
    public function fileFind(
        string $baseDir,
        string $query,
        ?string $extension = null,
        ?int $maxResults = 100
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

        // Limit results to prevent huge responses
        $totalCount = count($results);
        $results = array_slice($results, 0, $maxResults);

        return [
            'total_count' => $totalCount,
            'returned_count' => count($results),
            'base_dir' => $baseDir,
            'files' => $results,
        ];
    }

    /**
     *
     * Note: Results are automatically truncated to stay under 1MB response limit.
     * If truncation occurs, reduce contextLines (to 0-1) or use more specific search terms.
     * Search for content inside files
     *
     * Examples:
     * - baseDir: "/var/www/project"
     * - contentQuery: "processPayment" - find files containing this text
     * - filePattern: "Controller" - only search in files matching this pattern
     * - extension: "php" - only search .php files
     * - caseInsensitive: true - ignore case when searching
     * - contextLines: 2 - show 2 lines before/after each match
     * - maxResults: 50 - maximum number of files to return (default: 50, max recommended: 30)
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

        
        // Try indexed search first (if available and no file pattern specified)
        // File pattern filtering is not supported in index yet
        if ($filePattern === null) {
            try {
                $indexSearch = app(IndexSearchService::class);
                $results = $indexSearch->search($contentQuery, $baseDir, $extension, $maxResults);
                
                if (!empty($results['results'])) {
                    // Transform to expected format
                    return $this->formatIndexedResults($results);
                }
            } catch (\Exception $e) {
                Log::warning('Indexed search failed, using fallback', [
                    'error' => $e->getMessage(),
                    'query' => $contentQuery
                ]);
            }
        }
        
        // Fallback to traditional search
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

    private function formatIndexedResults(array $indexResults): array
    {
        $formatted = [];
        
        foreach ($indexResults['results'] as $result) {
            $matches = [];
            
            foreach ($result['matches'] as $match) {
                $matches[] = [
                    'line' => $match['line'],
                    'content' => $match['context'],
                    'context' => [
                        [
                            'line' => $match['line'],
                            'content' => $match['context'],
                            'match' => true
                        ]
                    ]
                ];
            }
            
            $formatted[] = [
                'path' => $result['path'],
                'match_count' => count($matches),
                'matches' => $matches,
            ];
        }
        
        return [
            'count' => count($formatted),
            'content_query' => implode(' ', $indexResults['query_tokens']),
            'results' => $formatted,
            'response_size' => $this->formatBytes(strlen(json_encode($formatted))),
            'truncated' => false,
            'message' => 'Results from indexed search (faster)',
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 2) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
