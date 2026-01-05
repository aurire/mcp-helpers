<?php

namespace App\Service\ContentIndexing;

use Illuminate\Support\Facades\DB;

class IndexSearchService
{
    public function __construct(
        private TokenizerService $tokenizer
    ) {}
    
    public function search(
        string $query,
        ?string $baseDir = null,
        ?string $extension = null,
        int $maxResults = 50
    ): array {
        $queryTokens = $this->tokenizer->tokenizeQuery($query);
        
        if (empty($queryTokens)) {
            return [
                'results' => [],
                'query_tokens' => [],
                'message' => 'No valid tokens in query'
            ];
        }
        
        // Find lines where ALL tokens appear together (same-line AND logic)
        $builder = DB::table('content_index')
            ->select([
                'file_path',
                'line_number',
                DB::raw('COUNT(DISTINCT token) as tokens_on_line'),
            ])
            ->whereIn('token', $queryTokens)
            ->groupBy('file_path', 'line_number')
            ->having('tokens_on_line', '=', count($queryTokens)); // All tokens must be on same line
        
        if ($baseDir) {
            $builder->where('file_path', 'LIKE', rtrim($baseDir, '/') . '/%');
        }
        
        if ($extension) {
            $builder->where('file_path', 'LIKE', '%.' . $extension);
        }
        
        $lineMatches = $builder->get();
        
        // Group by file and count matching lines
        $fileGroups = [];
        foreach ($lineMatches as $match) {
            if (!isset($fileGroups[$match->file_path])) {
                $fileGroups[$match->file_path] = [];
            }
            $fileGroups[$match->file_path][] = $match->line_number;
        }
        
        // Sort files by number of matching lines (most relevant first)
        uasort($fileGroups, fn($a, $b) => count($b) - count($a));
        
        // Limit to maxResults files
        $fileGroups = array_slice($fileGroups, 0, $maxResults, true);
        
        // Build results array
        $results = [];
        foreach ($fileGroups as $filePath => $lines) {
            $results[] = (object)[
                'file_path' => $filePath,
                'matching_lines' => count($lines),
            ];
        }
        
        // Enrich with actual matches
        $enrichedResults = [];
        foreach ($results as $result) {
            $matches = $this->getMatchDetailsForFile($result->file_path, $queryTokens);
            
            $enrichedResults[] = [
                'path' => $result->file_path,
                'match_count' => $result->matching_lines,
                'matches' => $matches,
            ];
        }
        
        return [
            'count' => count($enrichedResults),
            'query_tokens' => $queryTokens,
            'results' => $enrichedResults,
        ];
    }
    
    private function getMatchDetailsForFile(string $filePath, array $tokens): array
    {
        // Find lines where ALL tokens appear (same-line AND)
        $matches = DB::table('content_index')
            ->select(['line_number', 'original_token', 'context', 'token_type'])
            ->where('file_path', $filePath)
            ->whereIn('token', $tokens)
            ->orderBy('line_number')
            ->get();
        
        // Group by line number
        $lineGroups = [];
        foreach ($matches as $match) {
            $lineGroups[$match->line_number][] = $match;
        }
        
        // Filter to only lines with ALL tokens
        $result = [];
        foreach ($lineGroups as $lineNum => $lineMatches) {
            // Count distinct tokens on this line
            $distinctTokens = array_unique(array_map(fn($m) => $m->original_token, $lineMatches));
            
            // Only include if ALL query tokens are present
            if (count($distinctTokens) === count($tokens)) {
                $result[] = [
                    'line' => $lineNum,
                    'tokens' => $distinctTokens,
                    'context' => $lineMatches[0]->context,
                ];
            }
        }
        
        return $result;
    }
}
