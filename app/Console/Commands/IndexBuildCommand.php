<?php

namespace App\Console\Commands;

use App\Service\ContentIndexing\IndexBuilderService;
use Illuminate\Console\Command;

class IndexBuildCommand extends Command
{
    protected $signature = 'index:build
                          {path : Directory to index}
                          {--force : Force rebuild}
                          {--show-files : Show each file being indexed}';

    protected $description = 'Build content search index';

    /**
     * @param IndexBuilderService $builder
     * @return int
     */
    public function handle(IndexBuilderService $builder): int
    {
        $path = $this->argument('path');
        $showFiles = $this->option('show-files');

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }

        $this->info("Building index for: {$path}");
        $this->info("(Excluding: node_modules, vendor, etc.)");
        $this->newLine();

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');

        $stats = $builder->indexDirectory($path, function($s) use ($bar, $showFiles) {
            $bar->setProgress($s['indexed'] + $s['skipped']);

            // Show current file if show-files flag is set
            if ($showFiles && isset($s['current_file'])) {
                $shortPath = basename($s['current_file']);
                $bar->setMessage("Indexing: {$shortPath}");
            } else {
                $bar->setMessage("Indexed: {$s['indexed']}, Skipped: {$s['skipped']}");
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Files', number_format($stats['total'])],
                ['Indexed', number_format($stats['indexed'])],
                ['Skipped', number_format($stats['skipped'])],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("⚠ {$stats['errors']} errors occurred. Check logs for details.");
        }

        $this->info('✓ Index build complete!');
        $this->info('Run: php artisan index:stats --path=' . $path . ' to see statistics');

        return 0;
    }
}
