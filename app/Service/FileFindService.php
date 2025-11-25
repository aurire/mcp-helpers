<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

class FileFindService
{
    public function __construct(
        private FileSearchValidator $validator,
        private FileToolService $fileToolService
    ) {}

    /**
     * Search files by filename/path pattern
     */
    public function searchByFilename(
        string $baseDir,
        string $query,
        ?string $extension = null
    ): array
    {
        try {
            $cachedDirTree = new CachedDirTree($baseDir);
            $pattern = $this->validator->validateAndBuildPattern($query);
            return $cachedDirTree->search($pattern, $extension);
        } catch (InvalidArgumentException $e) {
            return [
                'error' => $e->getMessage(),
                'query' => $query,
            ];
        }
    }

    /**
     * Find files by extension only
     */
    public function findByExtension(string $baseDir, string $extension): array
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $extension)) {
            return [
                'error' => 'Invalid extension format. Only alphanumeric characters allowed.',
                'extension' => $extension,
            ];
        }

        $cachedDirTree = new CachedDirTree($baseDir);
        return $cachedDirTree->findByExtension($extension);
    }

    /**
     * Search for content inside files
     */
    public function searchInFiles(
        string $baseDir,
        string $contentQuery,
        ?string $filePattern = null,
        ?string $extension = null,
        bool $caseInsensitive = true,
        ?int $contextLines = 2,
        ?int $maxResults = 50
    ): array
    {
        // Get files to search
        $cachedTree = new CachedDirTree($baseDir);

        if ($filePattern) {
            try {
                $pattern = $this->validator->validateAndBuildPattern($filePattern);
                $files = $cachedTree->search($pattern, $extension);
            } catch (InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }
        } else if ($extension) {
            $files = $cachedTree->findByExtension($extension);
        } else {
            $files = $cachedTree->all();
        }

        // Build search pattern
        $escapedQuery = preg_quote($contentQuery, '/');
        $pattern = $caseInsensitive ? "/$escapedQuery/i" : "/$escapedQuery/";

        $results = [];

        foreach ($files as $file) {
            if (count($results) >= $maxResults) {
                break;
            }

            // Skip binary files
            if ($this->fileToolService->isBinaryFile($file['path'])) {
                continue;
            }

            $matches = $this->searchInFile($file['path'], $pattern, $contextLines);

            if (!empty($matches)) {
                $results[] = [
                    'path' => $file['path'],
                    'quick_hash' => $file['quick_hash'],
                    'match_count' => count($matches),
                    'matches' => $matches,
                ];
            }
        }


        // Apply smart truncation to keep response under 1MB
        $truncationResult = $this->smartTruncateResults($results, $contextLines);
        
        return [
            'count' => count($truncationResult['results']),
            'content_query' => $contentQuery,
            'files_searched' => count($files),
            'results' => $truncationResult['results'],
            'response_size' => $this->formatBytes($truncationResult['size']),
            'truncated' => $truncationResult['truncated'],
            'message' => $truncationResult['truncated'] ? 'Results were truncated to fit within size limits. Use more specific search terms or reduce contextLines.' : null,
        ];
    }

    /**
     * Search within a single file
     */
    private function searchInFile(string $path, string $pattern, int $contextLines): array
    {
        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }

        $matches = [];

        foreach ($lines as $lineNum => $line) {
            if (preg_match($pattern, $line)) {
                $matches[] = [
                    'line' => $lineNum + 1,
                    'content' => $line,
                    'context' => $this->getContext($lines, $lineNum, $contextLines),
                ];
            }
        }

        return $matches;
    }

    /**
     * Get surrounding context lines
     */
    private function getContext(array $lines, int $lineNum, int $contextLines): array
    {
        $start = max(0, $lineNum - $contextLines);
        $end = min(count($lines) - 1, $lineNum + $contextLines);

        $context = [];
        for ($i = $start; $i <= $end; $i++) {
            $context[] = [
                'line' => $i + 1,
                'content' => $lines[$i],
                'match' => $i === $lineNum,
            ];
        }

        return $context;
    }

    /**
     * Smart truncation to keep response under 900KB
     */
    private function smartTruncateResults(array $results, int $contextLines): array
    {
        $maxSizeBytes = 900 * 1024; // 900KB safety margin
        $currentSize = strlen(json_encode($results));
        
        if ($currentSize <= $maxSizeBytes) {
            return ['results' => $results, 'truncated' => false, 'size' => $currentSize];
        }
        
        // Strategy 1: Limit matches per file
        foreach ($results as &$result) {
            if (isset($result['matches']) && count($result['matches']) > 10) {
                $result['matches'] = array_slice($result['matches'], 0, 10);
                $result['truncated_matches'] = true;
            }
        }
        unset($result);
        
        $currentSize = strlen(json_encode($results));
        if ($currentSize <= $maxSizeBytes) {
            return ['results' => $results, 'truncated' => true, 'size' => $currentSize];
        }
        
        // Strategy 2: Reduce context lines
        if ($contextLines > 0) {
            foreach ($results as &$result) {
                foreach ($result['matches'] as &$match) {
                    if (isset($match['context'])) {
                        // Keep only matched line + 1 before/after
                        $match['context'] = array_slice($match['context'], 0, 3);
                    }
                }
                unset($match);
            }
            unset($result);
            
            $currentSize = strlen(json_encode($results));
            if ($currentSize <= $maxSizeBytes) {
                return ['results' => $results, 'truncated' => true, 'size' => $currentSize];
            }
        }
        
        // Strategy 3: Truncate long lines
        foreach ($results as &$result) {
            foreach ($result['matches'] as &$match) {
                if (isset($match['content']) && strlen($match['content']) > 200) {
                    $match['content'] = substr($match['content'], 0, 200) . '...';
                }
                if (isset($match['context'])) {
                    foreach ($match['context'] as &$ctx) {
                        if (strlen($ctx['content']) > 150) {
                            $ctx['content'] = substr($ctx['content'], 0, 150) . '...';
                        }
                    }
                    unset($ctx);
                }
            }
            unset($match);
        }
        unset($result);
        
        $currentSize = strlen(json_encode($results));
        if ($currentSize <= $maxSizeBytes) {
            return ['results' => $results, 'truncated' => true, 'size' => $currentSize];
        }
        
        // Strategy 4: Reduce number of files
        $targetFiles = (int) (count($results) * ($maxSizeBytes / $currentSize));
        $targetFiles = max($targetFiles, 5); // Keep at least 5 files
        $results = array_slice($results, 0, $targetFiles);
        
        return [
            'results' => $results,
            'truncated' => true,
            'size' => strlen(json_encode($results))
        ];
    }
    
    /**
     * Format bytes for human readability
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
