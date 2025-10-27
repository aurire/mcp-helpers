<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MemoryLink;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemoryLink>
 */
class MemoryLinkFactory extends Factory
{
    protected $model = MemoryLink::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->uuid(),
            'source_memory_id' => Memory::factory(),
            'target_memory_id' => Memory::factory(),
            'relationship_type' => $this->faker->randomElement(['references', 'part_of', 'related_to', 'depends_on', 'solves']),
            'description' => $this->faker->sentence(),
        ];
    }

    /**
     * State: references relationship
     */
    public function references(): self
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => 'references',
        ]);
    }

    /**
     * State: part_of relationship
     */
    public function partOf(): self
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => 'part_of',
        ]);
    }

    /**
     * State: related_to relationship
     */
    public function relatedTo(): self
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => 'related_to',
        ]);
    }

    /**
     * State: depends_on relationship
     */
    public function dependsOn(): self
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => 'depends_on',
        ]);
    }

    /**
     * State: solves relationship
     */
    public function solves(): self
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => 'solves',
        ]);
    }

    /**
     * State: for specific source memory
     */
    public function fromMemory(Memory $memory): self
    {
        return $this->state(fn (array $attributes) => [
            'source_memory_id' => $memory->id,
            'user_id' => $memory->user_id,
        ]);
    }

    /**
     * State: for specific target memory
     */
    public function toMemory(Memory $memory): self
    {
        return $this->state(fn (array $attributes) => [
            'target_memory_id' => $memory->id,
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
}
