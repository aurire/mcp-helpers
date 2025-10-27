<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Service\MemoryService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class MemoryUpdate
{
    public function __construct(
        private MemoryService $memoryService
    ) {}

    /**
     * Update a memory entry
     *
     * Usage:
     * - userId: the user's identifier
     * - key: the memory key to update
     * - valueMarkdown: new markdown content (optional)
     * - category: new category (optional)
     * - memoryType: new type (optional)
     * - importance: new importance level (optional)
     * - metadata: new metadata object (optional)
     * - status: new status (optional)
     */
    #[McpTool(name: 'memory_update')]
    public function memoryUpdate(
        string $userId,
        string $key,
        ?string $valueMarkdown = null,
        ?string $category = null,
        ?string $memoryType = null,
        ?int $importance = null,
        ?array $metadata = null,
        ?string $status = null
    ): array {
        $memory = $this->memoryService->getByKey($userId, $key);

        if (!$memory) {
            throw new RuntimeException("Memory not found: {$key}");
        }

        $updated = $this->memoryService->update(
            $memory,
            $valueMarkdown,
            $category,
            $memoryType,
            $importance,
            $metadata,
            $status
        );

        return [
            'success' => true,
            'memory' => $this->formatMemory($updated),
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
