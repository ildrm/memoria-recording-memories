<?php

namespace Database\Factories;

use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicationTarget>
 */
class PublicationTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'user_id' => fn (array $attributes): int => Publication::query()->findOrFail($attributes['publication_id'])->user_id,
            'social_account_id' => null,
            'target_key' => 'website',
            'type' => PublicationTargetType::Website,
            'provider' => null,
            'status' => PublicationTargetStatus::Pending,
            'content_override' => null,
            'settings' => [],
            'scheduled_at' => null,
            'dispatched_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    public function forSocialAccount(Publication $publication, SocialAccount $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'publication_id' => $publication->getKey(),
            'user_id' => $publication->user_id,
            'social_account_id' => $account->getKey(),
            'target_key' => $account->provider->value.':'.$account->getKey(),
            'type' => PublicationTargetType::Social,
            'provider' => $account->provider,
        ]);
    }

    public function publishedWebsite(Publication $publication): static
    {
        return $this->state(fn (array $attributes): array => [
            'publication_id' => $publication->getKey(),
            'user_id' => $publication->user_id,
            'social_account_id' => null,
            'target_key' => 'website',
            'type' => PublicationTargetType::Website,
            'provider' => null,
            'status' => PublicationTargetStatus::Published,
            'scheduled_at' => null,
            'dispatched_at' => $publication->published_at,
            'completed_at' => $publication->published_at,
            'failed_at' => null,
        ]);
    }
}
