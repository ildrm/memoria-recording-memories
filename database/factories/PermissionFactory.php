<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    public function definition(): array
    {
        $resource = fake()->unique()->word();

        return [
            'name' => $resource.'.view',
            'display_name' => 'View '.ucfirst($resource),
            'description' => fake()->sentence(),
        ];
    }
}
