<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileExists
{
    public function __construct(
        private FileToolService $fileToolService
    ) {}

    /**
     * Check if a path exists and get its type
     *
     * Usage:
     * - Specify path to check
     * - Returns whether path exists and its type (file or directory)
     *
     * Example:
     * - path: "/path/to/check"
     *
     * Returns:
     * - exists: bool
     * - type: "file" | "directory" | null
     */
    #[McpTool(name: 'file_exists')]
    public function fileExists(string $path): array
    {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $path)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Check if path exists
        if (!file_exists($path)) {
            return [
                'exists' => false,
                'type' => null,
                'path' => $path,
            ];
        }

        // Determine type
        $type = null;
        if (is_file($path)) {
            $type = 'file';
        } elseif (is_dir($path)) {
            $type = 'directory';
        } elseif (is_link($path)) {
            $type = 'symlink';
        }

        return [
            'exists' => true,
            'type' => $type,
            'path' => $path,
        ];
    }
}
