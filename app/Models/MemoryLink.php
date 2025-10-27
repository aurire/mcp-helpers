<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'source_memory_id',
        'target_memory_id',
        'relationship_type',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the source memory (the one that has the link)
     */
    public function sourceMemory(): BelongsTo
    {
        return $this->belongsTo(Memory::class, 'source_memory_id');
    }

    /**
     * Get the target memory (the one being linked to)
     */
    public function targetMemory(): BelongsTo
    {
        return $this->belongsTo(Memory::class, 'target_memory_id');
    }

    /**
     * Scope: get links for a specific user
     */
    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: get links from a specific source memory
     */
    public function scopeFromMemory($query, int $memoryId)
    {
        return $query->where('source_memory_id', $memoryId);
    }

    /**
     * Scope: get links to a specific target memory
     */
    public function scopeToMemory($query, int $memoryId)
    {
        return $query->where('target_memory_id', $memoryId);
    }

    /**
     * Scope: filter by relationship type
     */
    public function scopeRelationshipType($query, string $type)
    {
        return $query->where('relationship_type', strtolower($type));
    }
}
