<?php

namespace Database\Factories;

use App\Enums\EntryStatus;
use App\Enums\Mood;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'journal_id' => null,
            'title' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraphs(3, true).'</p>',
            'occurred_at' => fake()->dateTimeBetween('-8 years', 'now'),
            'timezone' => fake()->timezone(),
            'mood' => fake()->randomElement(Mood::cases()),
            'custom_mood' => null,
            'location_name' => fake()->optional()->city(),
            'latitude' => null,
            'longitude' => null,
            'importance' => fake()->numberBetween(0, 5),
            'status' => EntryStatus::Active,
            'is_favorite' => fake()->boolean(20),
            'archived_at' => null,
            'revision' => 1,
            'last_saved_at' => now(),
        ];
    }

    public function forJournal(Journal $journal): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $journal->user_id,
            'journal_id' => $journal->getKey(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => null,
            'body' => null,
            'status' => EntryStatus::Draft,
            'last_saved_at' => null,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EntryStatus::Active,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now()->subMonth(),
        ]);
    }

    public function favorite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_favorite' => true,
        ]);
    }
}
