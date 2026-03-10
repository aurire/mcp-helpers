<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Service\ContentIndexing\EmbeddingIndexService;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan index:embed-build <path> [--sync] [--show-files]
 *
 * --sync   Only re-embed files that changed since the last run (incremental).
 *          Without this flag a full build is performed (skips unchanged files
 *          automatically via embedding_hash check, so it's still idempotent).
 */
class IndexEmbedBuildCommand extends Command
{
    protected $signature = 'index:embed-build
                          {path : Directory to embed-index}
                          {--sync : Only process files changed since last run}
                          {--show-files : Print each file being processed}';

    protected $description = 'Build (or sync) the vector embedding index for concept search';

    public function handle(EmbeddingIndexService $svc): int
    {
        $path = $this->argument('path');

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }

        $mode = $this->option('sync') ? 'sync' : 'build';
        $showFiles = $this->option('show-files');

        $this->info($mode === 'sync'
            ? "Syncing embedding index for: {$path}"
            : "Building embedding index for: {$path}"
        );
        $this->newLine();

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');

        $callback = function (array $s) use ($bar, $showFiles) {
            $bar->setMaxSteps($s['total']);
            $bar->setProgress($s['indexed'] + $s['skipped'] + $s['errors']);

            if ($showFiles && isset($s['current_file'])) {
                $bar->setMessage('▸ ' . basename($s['current_file']));
            } else {
                $bar->setMessage("embedded: {$s['indexed']}  skipped: {$s['skipped']}  errors: {$s['errors']}");
            }
        };

        $stats = $mode === 'sync'
            ? $svc->syncDirectory($path, $callback)
            : $svc->indexDirectory($path, $callback);

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total files examined', number_format($stats['total'])],
                ['Newly embedded',       number_format($stats['indexed'])],
                ['Skipped (unchanged)',  number_format($stats['skipped'])],
                ['Errors',               $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("⚠  {$stats['errors']} error(s). Check logs for details.");
        }

        $this->info('✓ Embedding index complete!');
        $this->info('Run: php artisan file:concept-search "<query>" --dir=' . $path . ' to test it.');

        return 0;
    }
}
