<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Memory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    protected $model = Memory::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->uuid(),
            'key' => $this->faker->unique()->slug(3),
            'category' => $this->faker->randomElement(['technical', 'personal', 'decision', 'problem-solving', 'insight']),
            'memory_type' => $this->faker->randomElement(['topic', 'decision', 'problem', 'solution', 'conversation', 'note']),
            'value_markdown' => $this->faker->paragraphs(3, asText: true),
            'metadata' => [
                'source' => $this->faker->randomElement(['conversation', 'article', 'experience', 'decision']),
                'tags_count' => $this->faker->numberBetween(0, 5),
            ],
            'importance' => $this->faker->numberBetween(1, 10),
            'status' => $this->faker->randomElement(['active', 'archived', 'resolved']),
        ];
    }

    /**
     * State: high importance memory
     */
    public function highImportance(): self
    {
        return $this->state(fn (array $attributes) => [
            'importance' => $this->faker->numberBetween(8, 10),
        ]);
    }

    /**
     * State: low importance memory
     */
    public function lowImportance(): self
    {
        return $this->state(fn (array $attributes) => [
            'importance' => $this->faker->numberBetween(1, 3),
        ]);
    }

    /**
     * State: decision memory type
     */
    public function decision(): self
    {
        return $this->state(fn (array $attributes) => [
            'memory_type' => 'decision',
            'category' => $this->faker->randomElement(['decision', 'problem-solving', 'technical']),
        ]);
    }

    /**
     * State: problem memory type
     */
    public function problem(): self
    {
        return $this->state(fn (array $attributes) => [
            'memory_type' => 'problem',
            'category' => 'problem-solving',
        ]);
    }

    /**
     * State: resolved status
     */
    public function resolved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
        ]);
    }

    /**
     * State: archived status
     */
    public function archived(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
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
     * State: for specific category
     */
    public function category(string $category): self
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }
}
