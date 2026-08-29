<?php

namespace Database\Factories;

use App\Enums\ReactionType;
use App\Models\Publication;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory()->published(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(ReactionType::cases()),
        ];
    }
}
