<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Service\MemoryService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class MemoryCreate
{
    public function __construct(
        private MemoryService $memoryService
    ) {}

    /**
     * Create a new memory entry
     *
     * Usage:
     * - userId: identifier for the user (e.g., UUID)
     * - key: unique hierarchical key (e.g., "work:payment_gateways:processout")
     * - valueMarkdown: content in markdown format
     * - category: optional category for grouping (e.g., "technical", "personal")
     * - memoryType: optional type (e.g., "decision", "problem", "solution")
     * - importance: priority level (default: 5, higher = more important)
     * - metadata: optional JSON object for structured data
     * - status: 'active', 'archived', or 'resolved' (default: 'active')
     */
    #[McpTool(name: 'memory_create')]
    public function memoryCreate(
        string $userId,
        string $key,
        string $valueMarkdown,
        ?string $category = null,
        ?string $memoryType = null,
        int $importance = 5,
        ?array $metadata = null,
        string $status = 'active'
    ): array {
        $memory = $this->memoryService->create(
            $userId,
            $key,
            $valueMarkdown,
            $category,
            $memoryType,
            $importance,
            $metadata,
            $status
        );

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
