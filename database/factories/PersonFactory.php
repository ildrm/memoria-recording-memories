<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'display_name' => fake()->name(),
            'nickname' => fake()->optional()->firstName(),
            'notes' => fake()->optional()->sentence(),
            'avatar_path' => null,
            'relationship' => fake()->randomElement(['family', 'friend', 'colleague', 'mentor']),
        ];
    }
}
