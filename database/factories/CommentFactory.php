<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory()->published(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'moderated_by_user_id' => null,
            'body' => fake()->sentence(14),
            'status' => CommentStatus::Approved,
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'moderated_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommentStatus::Pending,
            'moderated_at' => null,
        ]);
    }
}
