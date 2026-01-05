<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexStatsCommand extends Command
{
    protected $signature = 'index:stats 
                          {--path= : Show stats for specific path}';
    
    protected $description = 'Show content index statistics';
    
    public function handle(): int
    {
        $path = $this->option('path');
        
        if ($path) {
            $files = DB::table('indexed_files')
                ->where('file_path', 'LIKE', rtrim($path, '/') . '/%')
                ->count();
            
            $tokens = DB::table('content_index')
                ->where('file_path', 'LIKE', rtrim($path, '/') . '/%')
                ->count();
            
            $this->info("Index Statistics for: {$path}");
            $this->newLine();
        } else {
            $files = DB::table('indexed_files')->count();
            $tokens = DB::table('content_index')->count();
            
            $this->info("Global Index Statistics");
            $this->newLine();
        }
        
        // Get metadata
        $metadata = DB::table('index_metadata')
            ->get()
            ->keyBy('key');
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Indexed Files', number_format($files)],
                ['Total Tokens', number_format($tokens)],
                ['Avg Tokens/File', $files > 0 ? number_format($tokens / $files, 1) : '0'],
                ['Last Full Index', $metadata['last_full_index']->value ?? 'Never'],
                ['Last Sync', $metadata['last_sync']->value ?? 'Never'],
                ['Index Version', $metadata['version']->value ?? 'Unknown'],
            ]
        );
        
        // Show top 10 most token-heavy files
        $this->newLine();
        $this->info('Top 10 files by token count:');
        
        $topFiles = DB::table('indexed_files')
            ->orderByDesc('token_count')
            ->limit(10);
        
        if ($path) {
            $topFiles->where('file_path', 'LIKE', rtrim($path, '/') . '/%');
        }
        
        $results = $topFiles->get();
        
        if ($results->isEmpty()) {
            $this->warn('No indexed files found.');
        } else {
            foreach ($results as $file) {
                $shortPath = str_replace($path ?? '', '', $file->file_path);
                $this->line(sprintf(
                    '  %s tokens - %s',
                    str_pad(number_format($file->token_count), 6, ' ', STR_PAD_LEFT),
                    $shortPath
                ));
            }
        }
        
        return 0;
    }
}
