<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryTag extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'memory_id',
        'user_id',
        'tag',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the memory this tag belongs to
     */
    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    /**
     * Scope: get tags for a specific user
     */
    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: find tags by name
     */
    public function scopeTag($query, string $tag)
    {
        return $query->where('tag', strtolower($tag));
    }
}
