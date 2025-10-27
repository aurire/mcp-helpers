<?php
declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\MemoryFactory;

/**
 * @property int                $id
 * @property string             $user_id
 * @property string             $key
 * @property string|null        $category
 * @property string|null        $memory_type
 * @property string             $value_markdown
 * @property array|null         $metadata
 * @property int                $importance
 * @property string             $status
 * @property Carbon             $created_at
 * @property Carbon             $updated_at
 *
 * @method static self updateOrCreate(array $attributes, array $values = [])
 * @method static self firstOrCreate(array $attributes, array $values = [])
 * @method static self create(array $attributes = [])
 * @method static Builder where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static MemoryFactory factory($count = null, $state = [])
 */
class Memory extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'category',
        'memory_type',
        'value_markdown',
        'metadata',
        'importance',
        'status',
    ];

    protected $casts = [
        'metadata' => 'json',
        'importance' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all tags associated with this memory
     */
    public function tags(): HasMany
    {
        return $this->hasMany(MemoryTag::class);
    }

    /**
     * Get all memories this memory links to (outgoing links)
     */
    public function linkedMemories(): HasMany
    {
        return $this->hasMany(MemoryLink::class, 'source_memory_id');
    }

    /**
     * Get all memories that link to this memory (incoming links)
     */
    public function referencedByMemories(): HasMany
    {
        return $this->hasMany(MemoryLink::class, 'target_memory_id');
    }

    /**
     * Scope: get memories for a specific user
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: filter by status
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: filter by category
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: filter by memory type
     */
    public function scopeMemoryType(Builder $query, string $memoryType): Builder
    {
        return $query->where('memory_type', strtolower($memoryType));
    }

    /**
     * Scope: order by importance descending
     */
    public function scopeOrderByImportance(Builder $query): Builder
    {
        return $query->orderByDesc('importance');
    }

    /**
     * Scope: order by most recently updated
     */
    public function scopeOrderByUpdated(Builder $query): Builder
    {
        return $query->orderByDesc('updated_at');
    }

    /**
     * Scope: order by creation date
     */
    public function scopeOrderByCreated(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
