<?php

namespace App\Service\ContentIndexing;

use Illuminate\Support\Facades\Log;

/**
 * Automatically sync content index when files are modified
 */
class AutoSyncService
{
    private bool $enabled = true;
    
    public function __construct(
        private IndexBuilderService $indexBuilder
    ) {}
    
    /**
     * Check if a file needs reindexing and do it automatically
     */
    public function syncFileIfNeeded(string $filePath): void
    {
        if (!$this->enabled) {
            return;
        }
        
        try {
            // Only sync if the file actually needs it (hash changed)
            if ($this->indexBuilder->needsReindexing($filePath)) {
                $this->indexBuilder->indexFile($filePath);
                
                Log::debug('Auto-synced content index', [
                    'file' => $filePath
                ]);
            }
        } catch (\Exception $e) {
            // Don't let indexing errors break file operations
            Log::warning('Auto-sync failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Disable auto-sync temporarily (for batch operations)
     */
    public function disable(): void
    {
        $this->enabled = false;
    }
    
    /**
     * Re-enable auto-sync
     */
    public function enable(): void
    {
        $this->enabled = true;
    }
    
    /**
     * Check if auto-sync is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
