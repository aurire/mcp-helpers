<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FileListDirectory
{
    public function __construct(
        private FileToolService $fileToolService
    ) {}

    /**
     * List contents of a directory
     *
     * Usage:
     * - Specify directory path to list
     * - Set recursive to true to list subdirectories
     * - Optionally provide glob pattern to filter results (e.g., "*.php", "test_*")
     *
     * Example:
     * - path: "/path/to/directory"
     * - recursive: false
     * - pattern: "*.php"
     *
     * Returns array of entries with path, type, size, and modification time
     */
    #[McpTool(name: 'file_list_directory')]
    public function fileListDirectory(
        string $path,
        bool $recursive = false,
        ?string $pattern = null
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $path)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Check if directory exists
        if (!file_exists($path)) {
            throw new RuntimeException("Path does not exist: {$path}");
        }

        // Verify it's a directory
        if (!is_dir($path)) {
            throw new RuntimeException("Path is not a directory: {$path}");
        }

        $entries = [];

        if ($recursive) {
            // Recursive listing
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                $filePath = $fileInfo->getPathname();
                
                // Apply pattern filter if specified
                if ($pattern !== null && !fnmatch($pattern, basename($filePath))) {
                    continue;
                }

                $entries[] = $this->buildEntryInfo($fileInfo);
            }
        } else {
            // Non-recursive listing
            $items = scandir($path);
            if ($items === false) {
                throw new RuntimeException("Failed to read directory: {$path}");
            }

            foreach ($items as $item) {
                // Skip . and ..
                if ($item === '.' || $item === '..') {
                    continue;
                }

                // Apply pattern filter if specified
                if ($pattern !== null && !fnmatch($pattern, $item)) {
                    continue;
                }

                $fullPath = $path . DIRECTORY_SEPARATOR . $item;
                $fileInfo = new \SplFileInfo($fullPath);
                $entries[] = $this->buildEntryInfo($fileInfo);
            }
        }

        return [
            'success' => true,
            'directory' => $path,
            'recursive' => $recursive,
            'pattern' => $pattern,
            'count' => count($entries),
            'entries' => $entries,
            'bulk_hint' => count($entries) >= 3 ? '💡 TIP: Found multiple files. Use bulk_execute for operations on multiple files. Example: bulk_execute(toolName: "file_read", operations: [["pathAndFilename" => "path1"], ["pathAndFilename" => "path2"]], parallel: true)' : null,
        ];
    }

    private function buildEntryInfo(\SplFileInfo $fileInfo): array
    {
        $type = 'unknown';
        if ($fileInfo->isFile()) {
            $type = 'file';
        } elseif ($fileInfo->isDir()) {
            $type = 'directory';
        } elseif ($fileInfo->isLink()) {
            $type = 'symlink';
        }

        return [
            'path' => $fileInfo->getPathname(),
            'name' => $fileInfo->getFilename(),
            'type' => $type,
            'size' => $fileInfo->isFile() ? $fileInfo->getSize() : null,
            'modified' => $fileInfo->getMTime(),
            'permissions' => $fileInfo->isReadable() ? decoct($fileInfo->getPerms() & 0777) : null,
        ];
    }
}
