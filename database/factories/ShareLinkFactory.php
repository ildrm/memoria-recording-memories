<?php

namespace Database\Factories;

use App\Models\Entry;
use App\Models\ShareLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareLink>
 */
class ShareLinkFactory extends Factory
{
    public function definition(): array
    {
        $plainTextToken = Str::random(64);

        return [
            'entry_id' => Entry::factory(),
            'publication_id' => null,
            'user_id' => fn (array $attributes): int => Entry::query()->findOrFail($attributes['entry_id'])->user_id,
            'label' => 'Private link for a trusted reader',
            'token_hash' => hash('sha256', $plainTextToken),
            'password_hash' => null,
            'include_attachments' => false,
            'track_views' => false,
            'view_count' => 0,
            'max_views' => null,
            'last_accessed_at' => null,
            'expires_at' => now()->addWeek(),
            'revoked_at' => null,
        ];
    }

    public function passwordProtected(string $password): static
    {
        return $this->state(fn (array $attributes): array => [
            'password_hash' => Hash::make($password),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
