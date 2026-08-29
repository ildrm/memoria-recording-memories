<?php

namespace Database\Factories;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'avatar_path' => null,
            'avatar_disk' => null,
            'biography' => fake()->optional()->paragraph(),
            'cover_image_path' => null,
            'cover_image_disk' => null,
            'website_url' => fake()->optional()->url(),
            'is_public' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => true,
        ]);
    }
}
