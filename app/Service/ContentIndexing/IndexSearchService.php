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
        
        // Build search query
        $builder = DB::table('content_index')
            ->select([
                'file_path',
                DB::raw('COUNT(DISTINCT token) as unique_matches'),
                DB::raw('COUNT(*) as total_occurrences'),
            ])
            ->whereIn('token', $queryTokens)
            ->groupBy('file_path')
            ->orderByDesc('unique_matches')
            ->orderByDesc('total_occurrences')
            ->limit($maxResults);
        
        if ($baseDir) {
            $builder->where('file_path', 'LIKE', rtrim($baseDir, '/') . '/%');
        }
        
        if ($extension) {
            $builder->where('file_path', 'LIKE', '%.' . $extension);
        }
        
        $results = $builder->get();
        
        // Enrich with actual matches
        $enrichedResults = [];
        foreach ($results as $result) {
            $matches = $this->getMatchDetailsForFile($result->file_path, $queryTokens);
            
            $enrichedResults[] = [
                'path' => $result->file_path,
                'match_count' => $result->unique_matches,
                'total_occurrences' => $result->total_occurrences,
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
        $matches = DB::table('content_index')
            ->select(['line_number', 'original_token', 'context', 'token_type'])
            ->where('file_path', $filePath)
            ->whereIn('token', $tokens)
            ->orderBy('line_number')
            ->limit(10)
            ->get();
        
        // Group by line number to avoid duplicates
        $lineGroups = [];
        foreach ($matches as $match) {
            $lineGroups[$match->line_number][] = $match;
        }
        
        $result = [];
        foreach ($lineGroups as $lineNum => $lineMatches) {
            $result[] = [
                'line' => $lineNum,
                'tokens' => array_map(fn($m) => $m->original_token, $lineMatches),
                'context' => $lineMatches[0]->context,
            ];
        }
        
        return $result;
    }
}
