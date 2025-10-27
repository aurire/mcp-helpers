<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Memory;

class MemoryService
{
    /**
     * Create a new memory
     */
    public function create(
        string $userId,
        string $key,
        string $valueMarkdown,
        ?string $category = null,
        ?string $memoryType = null,
        int $importance = 5,
        ?array $metadata = null,
        string $status = 'active'
    ): Memory {
        return Memory::create([
            'user_id' => $userId,
            'key' => $key,
            'value_markdown' => $valueMarkdown,
            'category' => $category,
            'memory_type' => $memoryType ? strtolower($memoryType) : null,
            'importance' => $importance,
            'metadata' => $metadata,
            'status' => $status,
        ]);
    }

    /**
     * Get a single memory by key for a user
     */
    public function getByKey(string $userId, string $key): ?Memory
    {
        return Memory::where('user_id', $userId)
            ->where('key', $key)
            ->first();
    }

    /**
     * Get a single memory by ID
     */
    public function getById(int $id): ?Memory
    {
        return Memory::find($id);
    }

    /**
     * Update a memory
     */
    public function update(
        Memory $memory,
        ?string $valueMarkdown = null,
        ?string $category = null,
        ?string $memoryType = null,
        ?int $importance = null,
        ?array $metadata = null,
        ?string $status = null
    ): Memory {
        $data = [];

        if ($valueMarkdown !== null) {
            $data['value_markdown'] = $valueMarkdown;
        }
        if ($category !== null) {
            $data['category'] = $category;
        }
        if ($memoryType !== null) {
            $data['memory_type'] = strtolower($memoryType);
        }
        if ($importance !== null) {
            $data['importance'] = $importance;
        }
        if ($metadata !== null) {
            $data['metadata'] = $metadata;
        }
        if ($status !== null) {
            $data['status'] = $status;
        }

        if (!empty($data)) {
            $memory->update($data);
        }

        return $memory;
    }

    /**
     * Delete a memory
     */
    public function delete(Memory $memory): bool
    {
        return $memory->delete();
    }

    /**
     * List memories by importance (descending) with pagination
     */
    public function listByImportance(
        string $userId,
        int $perPage = 20,
        int $page = 1,
        ?string $status = null,
        ?string $category = null
    ): array {
        $query = Memory::forUser($userId);

        if ($status !== null) {
            $query->status($status);
        }

        if ($category !== null) {
            $query->category($category);
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $memories = $query
            ->orderByImportance()
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalPages = (int) ceil($total / $perPage);

        return [
            'data' => $memories,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total),
                'has_more' => $page < $totalPages,
            ],
        ];
    }
}
