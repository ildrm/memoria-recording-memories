<?php

namespace Database\Factories;

use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPostFailure>
 */
class SocialPostFailureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'social_post_id' => SocialPost::factory()->failed(),
            'attempt' => 1,
            'error_class' => 'ProviderUnavailable',
            'error_code' => 'provider_unavailable',
            'message' => 'The fictional provider did not respond in time.',
            'is_retryable' => true,
            'context' => ['http_status' => 503],
            'occurred_at' => now(),
        ];
    }
}
