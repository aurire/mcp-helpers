<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use App\Service\ContentIndexing\AutoIndexHelper;
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
     * @param AutoIndexHelper $autoIndexHelper
     */
    public function __construct(
        protected FileToolService $fileToolService,
        protected AutoIndexHelper $autoIndexHelper,
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

        // Read file and get results with hash
        $results = $this->fileToolService->readFileAndPrepareResults($pathAndFilename);
        
        // Opportunistic reindex: Check if file hash changed and reindex if needed (non-blocking)
        $this->autoIndexHelper->checkAndReindexOnRead($pathAndFilename, $results['file_quick_hash']);
        
        return $results;

    }
}
