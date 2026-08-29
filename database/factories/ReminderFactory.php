<?php

namespace Database\Factories;

use App\Enums\ReminderFrequency;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'A quiet moment to write',
            'frequency' => ReminderFrequency::Weekly,
            'local_time' => '19:30:00',
            'day_of_week' => 0,
            'day_of_month' => null,
            'timezone' => fake()->timezone(),
            'channels' => ['mail'],
            'is_enabled' => true,
            'next_run_at' => now()->addWeek(),
            'last_sent_at' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_enabled' => false,
            'next_run_at' => null,
        ]);
    }
}
