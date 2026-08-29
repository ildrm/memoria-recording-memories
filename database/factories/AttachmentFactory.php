<?php

namespace Database\Factories;

use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Entry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'entry_id' => Entry::factory(),
            'user_id' => fn (array $attributes): int => Entry::query()->findOrFail($attributes['entry_id'])->user_id,
            'disk' => 'local',
            'path' => 'private/attachments/'.$uuid.'.jpg',
            'original_name' => 'memory-photo.jpg',
            'download_name' => 'memory-photo.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => fake()->numberBetween(80_000, 4_000_000),
            'media_type' => AttachmentMediaType::Image,
            'sha256' => hash('sha256', $uuid),
            'scan_status' => AttachmentScanStatus::Clean,
            'scanned_at' => now(),
            'metadata' => ['width' => 1600, 'height' => 1200],
        ];
    }

    public function pendingScan(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scan_status' => AttachmentScanStatus::Pending,
            'scanned_at' => null,
        ]);
    }

    public function document(): static
    {
        $uuid = (string) Str::uuid();

        return $this->state(fn (array $attributes): array => [
            'path' => 'private/attachments/'.$uuid.'.pdf',
            'original_name' => 'memory-note.pdf',
            'download_name' => 'memory-note.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'media_type' => AttachmentMediaType::Document,
            'sha256' => hash('sha256', $uuid),
            'metadata' => null,
        ]);
    }
}
