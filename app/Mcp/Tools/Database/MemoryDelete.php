<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Service\MemoryService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class MemoryDelete
{
    public function __construct(
        private MemoryService $memoryService
    ) {}

    /**
     * Delete a memory entry
     *
     * Usage:
     * - userId: the user's identifier
     * - key: the memory key to delete
     */
    #[McpTool(name: 'memory_delete')]
    public function memoryDelete(
        string $userId,
        string $key
    ): array {
        $memory = $this->memoryService->getByKey($userId, $key);

        if (!$memory) {
            throw new RuntimeException("Memory not found: {$key}");
        }

        $this->memoryService->delete($memory);

        return [
            'success' => true,
            'deleted' => true,
            'key' => $key,
            'message' => "Memory '{$key}' deleted successfully",
        ];
    }
}
