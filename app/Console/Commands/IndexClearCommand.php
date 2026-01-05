<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexClearCommand extends Command
{
    protected $signature = 'index:clear 
                          {--path= : Clear index for specific path only}
                          {--force : Skip confirmation}';
    
    protected $description = 'Clear the content search index';
    
    public function handle(): int
    {
        $path = $this->option('path');
        $force = $this->option('force');
        
        if ($path) {
            $count = DB::table('content_index')
                ->where('file_path', 'LIKE', rtrim($path, '/') . '/%')
                ->count();
            
            if (!$force && !$this->confirm("Clear index for {$count} files in {$path}?")) {
                $this->info('Cancelled.');
                return 0;
            }
            
            DB::table('content_index')
                ->where('file_path', 'LIKE', rtrim($path, '/') . '/%')
                ->delete();
            
            DB::table('indexed_files')
                ->where('file_path', 'LIKE', rtrim($path, '/') . '/%')
                ->delete();
            
            $this->info("✓ Cleared index for {$count} files in {$path}");
        } else {
            $totalTokens = DB::table('content_index')->count();
            $totalFiles = DB::table('indexed_files')->count();
            
            if (!$force && !$this->confirm("Clear entire index? ({$totalFiles} files, {$totalTokens} tokens)")) {
                $this->info('Cancelled.');
                return 0;
            }
            
            DB::table('content_index')->truncate();
            DB::table('indexed_files')->truncate();
            
            // Reset metadata
            DB::table('index_metadata')
                ->whereIn('key', ['total_files', 'total_tokens', 'last_full_index', 'last_sync'])
                ->update(['value' => '0', 'updated_at' => now()]);
            
            $this->info("✓ Cleared entire index ({$totalFiles} files, {$totalTokens} tokens)");
        }
        
        return 0;
    }
}
