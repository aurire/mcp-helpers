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
            'bulk_operations_available' => [
                'info' => '💡 BULK OPERATIONS: Use bulk_execute tool for multiple file operations at once',
                'performance' => 'Process 10+ operations in parallel (~0.008s vs ~0.030s sequential)',
                'supported_tools' => [
                    'file_create', 'file_read', 'file_delete', 'file_rename',
                    'file_rewrite', 'file_replace_line', 'file_insert_lines', 'file_delete_lines'
                ],
                'example' => [
                    'toolName' => 'file_read',
                    'operations' => [
                        ['pathAndFilename' => '/path/file1.php'],
                        ['pathAndFilename' => '/path/file2.php'],
                        ['pathAndFilename' => '/path/file3.php']
                    ],
                    'parallel' => true,
                    'continueOnError' => true
                ],
                'when_to_use' => 'Use bulk_execute when you need to perform 3+ similar file operations'
            ]
        ];
    }
}
