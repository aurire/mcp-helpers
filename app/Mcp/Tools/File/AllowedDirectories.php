<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;

class AllowedDirectories
{
    /**
     * @param FileToolService $fileToolService
     */
    public function __construct(
        private FileToolService $fileToolService
    )
    {
    }

    /**
     * Get list of allowed directories that can be searched and accessed
     *
     * Returns all directories/projects the LLM is allowed to work with.
     * Use these directories as baseDir parameter in file_find and other file operations.
     */
    #[McpTool(name: 'allowed_directories')]
    public function allowedDirectories(): array
    {
        $allowedPaths = $this->fileToolService->getAllowedPaths();

        return [
            'count' => count($allowedPaths),
            'directories' => array_map(function($path, $key) {
                $isNumeric = is_numeric($key);

                return [
                    'path' => $path,
                    'name' => $isNumeric ? basename($path) : $key,
                    'exists' => is_dir($path),
                ];
            }, $allowedPaths, array_keys($allowedPaths)),
        ];
    }
}
