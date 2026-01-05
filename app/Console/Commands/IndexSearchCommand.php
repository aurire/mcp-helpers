<?php

namespace App\Console\Commands;

use App\Service\ContentIndexing\IndexSearchService;
use Illuminate\Console\Command;

class IndexSearchCommand extends Command
{
    protected $signature = 'index:search 
                          {query : Search query}
                          {--path= : Limit to path}
                          {--ext= : File extension}
                          {--limit=10 : Max results}';
    
    protected $description = 'Search content index';
    
    public function handle(IndexSearchService $search): int
    {
        $query = $this->argument('query');
        $path = $this->option('path');
        $ext = $this->option('ext');
        $limit = (int)$this->option('limit');
        
        $results = $search->search($query, $path, $ext, $limit);
        
        $this->info("Query: {$query}");
        $this->info("Tokens: " . implode(', ', $results['query_tokens']));
        $this->info("Results: {$results['count']}");
        $this->newLine();
        
        foreach ($results['results'] as $result) {
            $this->line("<fg=green>{$result['path']}</>");
            $this->line("  Matching lines: {$result['match_count']}");
            
            foreach ($result['matches'] as $match) {
                $tokens = implode(', ', $match['tokens']);
                $this->line("    Line {$match['line']}: [{$tokens}]");
                $this->line("      " . trim($match['context']));
            }
            
            $this->newLine();
        }
        
        return 0;
    }
}
