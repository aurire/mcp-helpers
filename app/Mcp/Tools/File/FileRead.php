<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileRead
{
    /**
     * @var array $allowedPaths
     */
    private array $allowedPaths;

    /**
     * @param FileToolService $fileToolService
     */
    public function __construct(
        protected FileToolService $fileToolService,
    ) {
        $this->allowedPaths = $this->fileToolService->getAllowedPaths();
    }

    #[McpTool(name: 'file_read')]
    public function fileRead(string $pathAndFilename): array
    {
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }
        if (!$this->fileToolService->isPathAllowed($this->allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        return $this->fileToolService->readFileAndPrepareResults($pathAndFilename);
    }
}
