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
     * Search for content inside files
     *
     * Note: Results are automatically truncated to stay under 1MB response limit.
     * If truncation occurs, reduce contextLines (to 0-1) or use more specific search terms.
     *
     * Search modes:
     * - "indexed_only" (default): Fast search using index only. Returns empty if not found in index.
     *   Best for quick searches where you can retry with exhaustive if needed.
     * - "exhaustive": Guaranteed complete search. Syncs changed files to index, then searches.
     *   First run may be slow (full indexing), but subsequent runs are fast (incremental updates).
     *   Use when you need to be absolutely certain nothing is missed.
     * - "auto": Legacy behavior - tries index, falls back to grep on empty results (deprecated).
     *
     * Examples:
     * - baseDir: "/var/www/project"
     * - contentQuery: "processPayment" - find files containing this text
     * - filePattern: "Controller" - only search in files matching this pattern
     * - extension: "php" - only search .php files
     * - caseInsensitive: true - ignore case when searching
     * - contextLines: 2 - show 2 lines before/after each match
     * - maxResults: 50 - maximum number of files to return (default: 50)
     * - searchMode: "indexed_only" - fast indexed search (default), "exhaustive" for complete search, "auto" for legacy
     */
    #[McpTool(name: 'file_search_content')]
    public function searchContent(
        string $baseDir,
        string $contentQuery,
        ?string $filePattern = null,
        ?string $extension = null,
        bool $caseInsensitive = true,
        ?int $contextLines = 2,
        ?int $maxResults = 50,
        string $searchMode = 'indexed_only'
    ): array
    {
        if (!$this->fileToolService->isPathAllowed($this->allowedPaths, $baseDir)) {
            throw new RuntimeException("Access denied: Base directory is not in allowed paths");
        }

        if (!is_dir($baseDir)) {
            throw new RuntimeException("Base directory not found: {$baseDir}");
        }

        // Validate search mode
        $validModes = ['indexed_only', 'exhaustive', 'auto'];
        if (!in_array($searchMode, $validModes, true)) {
            throw new RuntimeException("Invalid searchMode. Must be one of: " . implode(', ', $validModes));
        }

        $indexSearchAttempted = false;
        $indexSearchFailed = false;
        $indexSearchEmpty = false;
        $syncStats = null;
        
        // Try indexed search if no file pattern specified (index doesn't support file patterns yet)
        if ($filePattern === null && $searchMode !== 'auto') {
            try {
                $indexSearch = app(IndexSearchService::class);
                $results = $indexSearch->search($contentQuery, $baseDir, $extension, $maxResults);
                
                $indexSearchAttempted = true;
                
                if (!empty($results['results'])) {
                    // Found results in index
                    return $this->formatIndexedResults($results, 'indexed_only', $syncStats);
                }
                
                // Index search returned empty
                $indexSearchEmpty = true;
                
                // For indexed_only mode, return empty results with clear message
                if ($searchMode === 'indexed_only') {
                    return [
                        'count' => 0,
                        'content_query' => $contentQuery,
                        'results' => [],
                        'search_method' => 'indexed_only',
                        'message' => 'No results found in indexed search. Use searchMode="exhaustive" to sync changed files and search again.',
                    ];
                }
                
                // For exhaustive mode: sync changed files and search again
                if ($searchMode === 'exhaustive') {
                    Log::info('Exhaustive search: syncing changed files', ['baseDir' => $baseDir]);
                    
                    $indexBuilder = app(IndexBuilderService::class);
                    $syncStats = $indexBuilder->syncChangedFiles($baseDir);
                    
                    Log::info('Sync completed', $syncStats);
                    
                    // Search again after sync
                    $results = $indexSearch->search($contentQuery, $baseDir, $extension, $maxResults);
                    
                    if (!empty($results['results'])) {
                        return $this->formatIndexedResults($results, 'exhaustive_after_sync', $syncStats);
                    }
                    
                    // Still no results after sync - genuinely not found
                    return [
                        'count' => 0,
                        'content_query' => $contentQuery,
                        'results' => [],
                        'search_method' => 'exhaustive_after_sync',
                        'sync_stats' => $syncStats,
                        'message' => 'No results found after syncing changed files. Content genuinely does not exist.',
                    ];
                }
                
            } catch (\Exception $e) {
                $indexSearchAttempted = true;
                $indexSearchFailed = true;
                
                Log::warning('Indexed search failed', [
                    'error' => $e->getMessage(),
                    'query' => $contentQuery
                ]);
                
                // For indexed_only mode, report the failure
                if ($searchMode === 'indexed_only') {
                    throw new RuntimeException(
                        "Indexed search failed: {$e->getMessage()}. Try searchMode=\"exhaustive\" for complete search."
                    );
                }
            }
        }
        
        // Fallback to traditional grep search for:
        // - auto mode (legacy behavior)
        // - when filePattern is specified (not supported by index)
        // - when indexed search failed in exhaustive mode
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

        // Add metadata about search method
        $results['search_method'] = 'grep';
        
        if ($indexSearchAttempted) {
            if ($indexSearchFailed) {
                $results['message'] = 'Used filesystem search (grep) because indexed search failed.';
            } elseif ($indexSearchEmpty) {
                $results['message'] = 'Used filesystem search (grep) because indexed search found no results.';
            }
        } else {
            $reason = $filePattern !== null ? 'file pattern specified (not yet supported by index)' : 'auto mode (legacy)';
            $results['message'] = "Used filesystem search (grep) because {$reason}.";
        }

        return $results;
    }

    private function formatIndexedResults(array $indexResults, string $searchMethod, ?array $syncStats = null): array
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
        
        $response = [
            'count' => count($formatted),
            'content_query' => implode(' ', $indexResults['query_tokens']),
            'results' => $formatted,
            'response_size' => $this->formatBytes(strlen(json_encode($formatted))),
            'truncated' => false,
            'search_method' => $searchMethod,
        ];
        
        // Add sync stats if available
        if ($syncStats !== null) {
            $response['sync_stats'] = $syncStats;
        }
        
        // Customize message based on search method
        if ($searchMethod === 'indexed_only') {
            $response['message'] = 'Results from indexed search (fast, may not include recently changed files)';
        } elseif ($searchMethod === 'exhaustive_after_sync') {
            if ($syncStats && $syncStats['indexed'] > 0) {
                $response['message'] = "Results after syncing {$syncStats['indexed']} changed file(s). Index is now up-to-date.";
            } else {
                $response['message'] = 'Results from up-to-date index (no files needed syncing).';
            }
        }
        
        return $response;
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
