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

        return [
            'count' => count($results),
            'content_query' => $contentQuery,
            'files_searched' => count($files),
            'results' => $results,
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
}
