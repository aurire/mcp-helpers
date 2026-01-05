<?php

namespace App\Console\Commands;

use App\Service\ContentIndexing\IndexBuilderService;
use Illuminate\Console\Command;

class IndexSyncCommand extends Command
{
    protected $signature = 'index:sync 
                          {path : Directory to sync}';
    
    protected $description = 'Sync index (update only changed files)';
    
    public function handle(IndexBuilderService $builder): int
    {
        $path = $this->argument('path');
        
        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }
        
        $this->info("Finding changed files in: {$path}");
        
        // First, find changed files
        $changedFiles = $builder->findChangedFiles($path);
        
        if (empty($changedFiles)) {
            $this->info('✓ No changes detected - index is up to date!');
            return 0;
        }
        
        $this->info("Found " . count($changedFiles) . " changed files");
        $this->info("Syncing index...");
        
        $bar = $this->output->createProgressBar(count($changedFiles));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        
        $stats = $builder->syncChangedFiles($path, function($s) use ($bar) {
            $bar->setProgress($s['indexed'] + $s['skipped']);
            
            if (isset($s['current_file'])) {
                $shortPath = basename($s['current_file']);
                $bar->setMessage("Syncing: {$shortPath}");
            }
        });
        
        $bar->finish();
        $this->newLine(2);
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Changed Files', $stats['total']],
                ['Reindexed', $stats['indexed']],
                ['Skipped', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );
        
        $this->info('✓ Index sync complete!');
        
        return 0;
    }
}
