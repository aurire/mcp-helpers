<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileGetVersion
{
    public function __construct(
        private FileVersionService $fileVersionService
    ) {}

    /**
     * Get the full content of a specific file version
     *
     * Retrieve a saved version of a file by either version_id or file_quick_hash.
     * This is useful for:
     * - Viewing the state of a file at a specific point in time
     * - Comparing different versions
     * - Examining what changed in a particular operation
     * - Finding the right version to restore
     *
     * You must provide ONE of:
     * - versionId: The numeric ID from file_list_versions
     * - fileQuickHash: The hash from file_list_versions or a previous operation
     *
     * Example:
     * - versionId: 42 (get version #42)
     * OR
     * - fileQuickHash: "8f2aacddbde03ffd" (get version by hash)
     *
     * Returns:
     * - Full file content (1-indexed line array like file_read)
     * - Version metadata (operation type, timestamp, etc.)
     * - Operation summary (what changed)
     */
    #[McpTool(name: 'file_get_version')]
    public function fileGetVersion(
        ?int $versionId = null,
        ?string $fileQuickHash = null
    ): array {
        // Validate that at least one parameter is provided
        if ($versionId === null && $fileQuickHash === null) {
            throw new RuntimeException('Must provide either versionId or fileQuickHash');
        }

        // Get version
        $version = $this->fileVersionService->getVersion($versionId, $fileQuickHash);

        if ($version === null) {
            if ($versionId) {
                throw new RuntimeException("Version not found: {$versionId}");
            } else {
                throw new RuntimeException("Version not found: {$fileQuickHash}");
            }
        }

        // Add helpful tips
        $version['tips'] = [
            'Content is returned as 1-indexed array (same format as file_read)',
            'Use file_restore_version to restore file to this version',
            'Use file_list_versions to see all available versions',
            'The file_quick_hash can be used with file_replace_line and other tools',
        ];

        return $version;
    }
}
