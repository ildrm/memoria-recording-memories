<?php

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'format' => 'zip',
            'status' => ExportStatus::Pending,
            'options' => ['entries' => true, 'attachments' => true],
            'disk' => null,
            'path' => null,
            'filename' => null,
            'size_bytes' => null,
            'requested_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'expires_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];
    }

    public function ready(): static
    {
        $uuid = (string) Str::uuid();

        return $this->state(fn (array $attributes): array => [
            'status' => ExportStatus::Ready,
            'disk' => 'local',
            'path' => 'private/exports/'.$uuid.'.zip',
            'filename' => 'recording-memories-export.zip',
            'size_bytes' => 1_250_000,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
