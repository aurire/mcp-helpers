<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Service\MemoryService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class MemoryRead
{
    public function __construct(
        private MemoryService $memoryService
    ) {}

    /**
     * Read a single memory entry by key
     *
     * Usage:
     * - userId: the user's identifier
     * - key: the memory key to retrieve
     */
    #[McpTool(name: 'memory_read')]
    public function memoryRead(
        string $userId,
        string $key
    ): array {
        $memory = $this->memoryService->getByKey($userId, $key);

        if (!$memory) {
            throw new RuntimeException("Memory not found: {$key}");
        }

        return [
            'success' => true,
            'memory' => $this->formatMemory($memory),
        ];
    }

    private function formatMemory($memory): array
    {
        return [
            'id' => $memory->id,
            'user_id' => $memory->user_id,
            'key' => $memory->key,
            'category' => $memory->category,
            'memory_type' => $memory->memory_type,
            'value_markdown' => $memory->value_markdown,
            'metadata' => $memory->metadata,
            'importance' => $memory->importance,
            'status' => $memory->status,
            'created_at' => $memory->created_at?->toIso8601String(),
            'updated_at' => $memory->updated_at?->toIso8601String(),
        ];
    }
}
