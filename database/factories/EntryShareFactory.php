<?php

namespace Database\Factories;

use App\Enums\SharePermission;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryShare>
 */
class EntryShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'shared_by_user_id' => fn (array $attributes): int => Entry::query()->findOrFail($attributes['entry_id'])->user_id,
            'shared_with_user_id' => User::factory(),
            'permission' => SharePermission::View,
            'include_attachments' => false,
            'expires_at' => now()->addMonth(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
