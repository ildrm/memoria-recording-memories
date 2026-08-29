<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        $displayName = fake()->unique()->jobTitle();

        return [
            'name' => Str::slug($displayName),
            'display_name' => $displayName,
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }
}
