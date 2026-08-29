<?php

namespace Database\Factories;

use App\Models\Publication;
use App\Models\PublicationMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PublicationMedia>
 */
class PublicationMediaFactory extends Factory
{
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'publication_id' => Publication::factory(),
            'user_id' => fn (array $attributes): int => Publication::query()->findOrFail($attributes['publication_id'])->user_id,
            'source_attachment_id' => null,
            'disk' => (string) config('memoria.disks.sanitized_media', 'local'),
            'path' => 'publication-media/'.$uuid.'.jpg',
            'original_name' => 'sunlit-garden.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 1_000_000),
            'alt_text' => 'Sunlight falling across a quiet garden path',
            'sort_order' => 0,
            'is_featured' => true,
            'metadata_stripped' => true,
            'metadata' => ['width' => 1400, 'height' => 933],
        ];
    }
}
