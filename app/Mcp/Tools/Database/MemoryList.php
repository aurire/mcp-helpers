<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Service\MemoryService;
use PhpMcp\Server\Attributes\McpTool;

class MemoryList
{
    public function __construct(
        private MemoryService $memoryService
    ) {}

    /**
     * List memories sorted by importance (descending) with pagination
     *
     * Usage:
     * - userId: the user's identifier
     * - perPage: items per page (default: 20)
     * - page: page number 1-based (default: 1)
     * - status: filter by status (optional: 'active', 'archived', 'resolved')
     * - category: filter by category (optional)
     */
    #[McpTool(name: 'memory_list')]
    public function memoryList(
        string $userId,
        int $perPage = 20,
        int $page = 1,
        ?string $status = null,
        ?string $category = null
    ): array {
        $result = $this->memoryService->listByImportance(
            $userId,
            $perPage,
            $page,
            $status,
            $category
        );

        return [
            'success' => true,
            'memories' => $result['data']->map(fn($memory) => $this->formatMemory($memory))->toArray(),
            'pagination' => $result['pagination'],
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
