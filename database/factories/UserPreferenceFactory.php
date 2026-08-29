<?php

namespace Database\Factories;

use App\Enums\AppearancePreference;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'locale' => 'en',
            'timezone' => fake()->timezone(),
            'appearance' => AppearancePreference::System,
            'on_this_day_enabled' => false,
            'notification_preferences' => [
                'security' => true,
                'product' => true,
                'social' => true,
            ],
            'privacy_preferences' => [
                'default_entry_visibility' => 'private',
            ],
        ];
    }
}
