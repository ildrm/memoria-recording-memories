<?php

namespace Database\Factories;

use App\Enums\Mood;
use App\Models\Entry;
use App\Models\EntryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryVersion>
 */
class EntryVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'user_id' => fn (array $attributes): int => Entry::query()->findOrFail($attributes['entry_id'])->user_id,
            'version' => 1,
            'title' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraphs(2, true).'</p>',
            'occurred_at' => fake()->dateTimeBetween('-8 years', 'now'),
            'timezone' => 'UTC',
            'mood' => fake()->randomElement(Mood::cases()),
            'custom_mood' => null,
            'location_name' => fake()->optional()->city(),
            'latitude' => null,
            'longitude' => null,
            'importance' => fake()->numberBetween(0, 5),
            'metadata' => ['source' => 'manual-save'],
            'reason' => 'manual-save',
        ];
    }
}
