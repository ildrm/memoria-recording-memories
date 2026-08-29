<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\PublicationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicationVersion>
 */
class PublicationVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'user_id' => fn (array $attributes): int => Publication::query()->findOrFail($attributes['publication_id'])->user_id,
            'version' => 1,
            'title' => fake()->sentence(6),
            'excerpt' => fake()->paragraph(),
            'body' => '<p>'.fake()->paragraphs(3, true).'</p>',
            'status' => PublicationStatus::Draft,
            'settings' => [
                'comments_enabled' => false,
                'reactions_enabled' => false,
                'search_engine_indexing' => false,
                'topics' => [],
            ],
            'reason' => 'manual-save',
        ];
    }
}
