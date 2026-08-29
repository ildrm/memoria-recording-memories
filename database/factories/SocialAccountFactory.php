<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        $provider = fake()->randomElement(SocialProvider::cases());

        return [
            'user_id' => User::factory(),
            'provider' => $provider,
            'provider_user_id' => fake()->uuid(),
            'username' => fake()->userName(),
            'display_name' => fake()->name(),
            'server_url' => $provider === SocialProvider::Mastodon ? 'https://social.example' : null,
            'access_token' => 'fictional-access-token-'.fake()->uuid(),
            'refresh_token' => 'fictional-refresh-token-'.fake()->uuid(),
            'token_expires_at' => now()->addMonth(),
            'scopes' => ['profile.read', 'posts.write'],
            'metadata' => ['environment' => 'fake'],
            'connected_at' => now()->subWeek(),
            'last_refreshed_at' => now()->subDay(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_expires_at' => now()->subHour(),
        ]);
    }
}
