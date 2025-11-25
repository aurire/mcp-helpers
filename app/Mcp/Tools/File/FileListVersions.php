<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\FileVersionService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileListVersions
{
    public function __construct(
        private FileVersionService $fileVersionService,
        private FileToolService $fileToolService
    ) {}

    /**
     * List all saved versions of a file
     *
     * This tool shows the version history for a file, including:
     * - When each version was created
     * - What operation created it (replace, insert, delete, create, restore)
     * - How many lines and size
     * - Operation details (what changed)
     *
     * Use this to:
     * - View the history of changes to a file
     * - Find a specific version to restore
     * - See what operations were performed
     * - Track file modifications over time
     *
     * Example:
     * - pathAndFilename: "/path/to/file.php"
     * - limit: 20 (show last 20 versions)
     * - offset: 0 (start from most recent)
     *
     * Returns:
     * - List of versions with metadata
     * - Total version count
     * - Pagination information
     * - Quick tips for using versions
     */
    #[McpTool(name: 'file_list_versions')]
    public function fileListVersions(
        string $pathAndFilename,
        int $limit = 50,
        int $offset = 0
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Validate limits
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException("Limit must be between 1 and 100");
        }

        if ($offset < 0) {
            throw new RuntimeException("Offset must be >= 0");
        }

        // Check if file exists
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        // Get versions
        $result = $this->fileVersionService->listVersions($pathAndFilename, $limit, $offset);

        // Add helpful context
        $result['file'] = $pathAndFilename;
        $result['tips'] = [
            'Use file_get_version with version_id to see full content of a specific version',
            'Use file_restore_version with version_id to restore file to a previous state',
            'Versions are saved automatically after replace, insert, delete operations',
            'To see older versions, increase the offset parameter',
        ];

        if ($result['total'] === 0) {
            $result['message'] = 'No versions found for this file. Versions are created automatically when you use file manipulation tools.';
        }

        return $result;
    }
}
