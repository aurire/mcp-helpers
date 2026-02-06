<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileCreateDirectory
{
    public function __construct(
        private FileToolService $fileToolService
    ) {}

    /**
     * Create a new directory
     *
     * Usage:
     * - Specify path where directory should be created
     * - Set recursive to true to create parent directories if needed
     * - Set mode for Unix permissions (default: 0755)
     *
     * Example:
     * - path: "/path/to/new/directory"
     * - recursive: true
     * - mode: 0755
     *
     * Returns success confirmation with created directory details
     */
    #[McpTool(name: 'file_create_directory')]
    public function fileCreateDirectory(
        string $path,
        bool $recursive = true,
        int $mode = 0755
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $path)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Check if directory already exists
        if (file_exists($path)) {
            if (is_dir($path)) {
                throw new RuntimeException("Directory already exists: {$path}");
            }
            throw new RuntimeException("Path exists but is not a directory: {$path}");
        }

        // If not recursive, check parent directory exists
        if (!$recursive) {
            $parentDir = dirname($path);
            if (!is_dir($parentDir)) {
                throw new RuntimeException("Parent directory does not exist: {$parentDir}. Use recursive: true to create parent directories.");
            }
        }

        // Create the directory
        if (!mkdir($path, $mode, $recursive)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }

        // Get directory info
        $dirInfo = [
            'path' => $path,
            'permissions' => decoct(fileperms($path) & 0777),
            'created' => filectime($path),
            'modified' => filemtime($path),
        ];

        return [
            'success' => true,
            'created' => true,
            'directory' => $path,
            'recursive' => $recursive,
            'dir_info' => $dirInfo,
        ];
    }
}
