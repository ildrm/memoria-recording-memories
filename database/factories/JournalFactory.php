<?php

namespace Database\Factories;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Quiet Mornings', 'Travel Notes', 'Family Stories', 'Dream Journal', 'Small Joys']);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['book-open', 'map', 'heart', 'moon', 'sparkles']),
            'cover_path' => null,
            'sort_order' => fake()->numberBetween(0, 20),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now()->subDays(10),
        ]);
    }
}
