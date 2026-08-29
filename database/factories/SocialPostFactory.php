<?php

namespace Database\Factories;

use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
{
    public function definition(): array
    {
        $idempotencyKey = hash('sha256', (string) Str::uuid());

        return [
            'publication_id' => Publication::factory(),
            'user_id' => fn (array $attributes): int => Publication::query()->findOrFail($attributes['publication_id'])->user_id,
            'publication_target_id' => null,
            'social_account_id' => null,
            'provider' => SocialProvider::Mastodon,
            'status' => SocialPostStatus::Pending,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', $idempotencyKey.'request'),
            'content' => fake()->sentence(18),
            'remote_post_id' => null,
            'remote_url' => null,
            'attempt_count' => 0,
            'scheduled_at' => null,
            'last_attempted_at' => null,
            'next_retry_at' => null,
            'published_at' => null,
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'provider_metadata' => [],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SocialPostStatus::Published,
            'remote_post_id' => fake()->uuid(),
            'remote_url' => 'https://social.example/@demo/'.fake()->unique()->numerify('########'),
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SocialPostStatus::Failed,
            'attempt_count' => 3,
            'last_attempted_at' => now(),
            'failed_at' => now(),
            'error_code' => 'provider_unavailable',
            'error_message' => 'The fictional provider was unavailable after safe retries.',
        ]);
    }
}
