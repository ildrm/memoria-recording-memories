<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'user_id' => User::factory(),
            'source_entry_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('####'),
            'excerpt' => fake()->paragraph(),
            'body' => '<p>'.fake()->paragraphs(4, true).'</p>',
            'topics' => [],
            'status' => PublicationStatus::Draft,
            'comments_enabled' => false,
            'reactions_enabled' => false,
            'search_engine_indexing' => false,
            'privacy_reviewed_at' => null,
            'scheduled_at' => null,
            'published_at' => null,
            'unpublished_at' => null,
            'archived_at' => null,
            'source_revision' => null,
            'revision' => 1,
        ];
    }

    public function fromEntry(Entry $entry): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $entry->user_id,
            'source_entry_id' => $entry->getKey(),
            'title' => $entry->title ?? 'Untitled memory',
            'body' => $entry->body ?? '',
            'source_revision' => $entry->revision,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublicationStatus::Published,
            'comments_enabled' => true,
            'reactions_enabled' => true,
            'search_engine_indexing' => true,
            'privacy_reviewed_at' => now()->subDay(),
            'published_at' => now()->subHours(2),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublicationStatus::Scheduled,
            'privacy_reviewed_at' => now(),
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublicationStatus::Unpublished,
            'privacy_reviewed_at' => now()->subDays(3),
            'published_at' => now()->subDays(2),
            'unpublished_at' => now()->subDay(),
        ]);
    }
}
