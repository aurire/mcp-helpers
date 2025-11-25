<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileCleanupVersions
{
    public function __construct(
        private FileVersionService $fileVersionService,
        private FileToolService $fileToolService
    ) {}

    /**
     * Clean up old file versions based on retention strategy
     *
     * This tool helps manage storage by removing old versions.
     * Always run with dryRun=true first to see what would be deleted!
     *
     * Strategies:
     * 1. keep_last_n: Keep only the N most recent versions per file
     * 2. keep_days: Keep only versions from the last N days
     *
     * Parameters:
     * - pathAndFilename: Clean versions for specific file (optional, null = all files)
     * - strategy: 'keep_last_n' or 'keep_days'
     * - keepCount: For keep_last_n strategy (default: from config)
     * - keepDays: For keep_days strategy (default: 30)
     * - dryRun: true = show what would be deleted, false = actually delete
     *
     * Examples:
     * 1. See what would be cleaned (safe):
     *    - strategy: "keep_last_n"
     *    - keepCount: 20
     *    - dryRun: true
     *
     * 2. Clean all files, keep last 30 versions each:
     *    - strategy: "keep_last_n"
     *    - keepCount: 30
     *    - dryRun: false
     *
     * 3. Clean one file, keep last 7 days:
     *    - pathAndFilename: "/path/to/file.php"
     *    - strategy: "keep_days"
     *    - keepDays: 7
     *    - dryRun: false
     *
     * Returns:
     * - List of versions that will be/were deleted
     * - Summary of cleanup operation
     * - Storage savings estimate
     */
    #[McpTool(name: 'file_cleanup_versions')]
    public function fileCleanupVersions(
        ?string $pathAndFilename = null,
        string $strategy = 'keep_last_n',
        ?int $keepCount = null,
        ?int $keepDays = null,
        bool $dryRun = true
    ): array {
        // Validate path if provided
        if ($pathAndFilename !== null) {
            $allowedPaths = $this->fileToolService->getAllowedPaths();
            if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
                throw new RuntimeException("Access denied: Path is not within allowed directories");
            }

            if (!file_exists($pathAndFilename)) {
                throw new RuntimeException("File not found: {$pathAndFilename}");
            }
        }

        // Validate strategy
        if (!in_array($strategy, ['keep_last_n', 'keep_days'])) {
            throw new RuntimeException("Invalid strategy. Must be 'keep_last_n' or 'keep_days'");
        }

        // Validate strategy-specific parameters
        if ($strategy === 'keep_last_n' && $keepCount !== null && $keepCount < 1) {
            throw new RuntimeException("keepCount must be >= 1");
        }

        if ($strategy === 'keep_days' && $keepDays !== null && $keepDays < 1) {
            throw new RuntimeException("keepDays must be >= 1");
        }

        // Run cleanup
        $result = $this->fileVersionService->cleanupVersions(
            $pathAndFilename,
            $strategy,
            $keepCount,
            $keepDays,
            $dryRun
        );

        // Add helpful context
        $result['target'] = $pathAndFilename ? "Single file: {$pathAndFilename}" : "All files";
        
        if ($dryRun) {
            $result['warning'] = '⚠️  DRY RUN: No versions were actually deleted. Set dryRun=false to perform cleanup.';
        } else {
            $result['success'] = '✅ Cleanup completed successfully';
        }

        $result['tips'] = [
            'Always run with dryRun=true first to preview changes',
            'Consider using keep_last_n=50 as a safe default',
            'Versions are automatically created, so cleanup helps manage storage',
            'Deleted versions cannot be recovered',
        ];

        return $result;
    }
}
