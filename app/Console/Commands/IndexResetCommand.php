<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexResetCommand extends Command
{
    protected $signature = 'index:reset 
                            {--confirm : Skip confirmation prompt}';

    protected $description = 'Clear and reset the content index (deletes all indexed data)';

    public function handle(): int
    {
        if (!$this->option('confirm')) {
            if (!$this->confirm('This will delete ALL indexed data. Continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->info('Clearing content index...');

        // Get counts before deletion
        $tokenCount = DB::table('content_index')->count();
        $fileCount = DB::table('indexed_files')->count();

        // Delete all indexed data
        DB::transaction(function () {
            DB::table('content_index')->truncate();
            DB::table('indexed_files')->truncate();
            DB::table('index_metadata')->truncate();
        });

        $this->components->success("Index reset complete!");
        $this->line("  Removed {$tokenCount} tokens from {$fileCount} files");
        $this->line('');
        $this->line('  Run <fg=cyan>php artisan index:build</> to rebuild the index.');

        return Command::SUCCESS;
    }
}
