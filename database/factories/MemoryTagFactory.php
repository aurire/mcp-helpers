<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MemoryTag;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemoryTag>
 */
class MemoryTagFactory extends Factory
{
    protected $model = MemoryTag::class;

    public function definition(): array
    {
        return [
            'memory_id' => Memory::factory(),
            'user_id' => $this->faker->uuid(),
            'tag' => strtolower($this->faker->word()),
        ];
    }

    /**
     * State: for specific memory
     */
    public function forMemory(Memory $memory): self
    {
        return $this->state(fn (array $attributes) => [
            'memory_id' => $memory->id,
            'user_id' => $memory->user_id,
        ]);
    }

    /**
     * State: for specific user
     */
    public function forUser(string $userId): self
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    /**
     * State: specific tag
     */
    public function tag(string $tag): self
    {
        return $this->state(fn (array $attributes) => [
            'tag' => strtolower($tag),
        ]);
    }
}
