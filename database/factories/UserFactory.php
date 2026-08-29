<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            UserProfile::factory()->for($user)->create();
            UserPreference::factory()->for($user)->create();
        });
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disabled_at' => now(),
        ]);
    }

    public function withRole(RoleName $roleName): static
    {
        return $this->afterCreating(function (User $user) use ($roleName): void {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName->value],
                [
                    'display_name' => $roleName->label(),
                    'is_system' => true,
                ],
            );

            $user->assignRole($role);
        });
    }

    public function superAdministrator(): static
    {
        return $this->withRole(RoleName::SuperAdministrator);
    }
}
