<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Publication;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory()->published(),
            'comment_id' => null,
            'reporter_user_id' => User::factory(),
            'assigned_to_user_id' => null,
            'reason' => 'spam',
            'details' => 'This fictional report exists to demonstrate the moderation workflow.',
            'status' => ReportStatus::Open,
            'resolution' => null,
            'moderation_notes' => null,
            'resolved_at' => null,
        ];
    }

    public function resolved(User $moderator): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to_user_id' => $moderator->getKey(),
            'status' => ReportStatus::Resolved,
            'resolution' => 'Reviewed and resolved in the fictional demo data.',
            'moderation_notes' => 'No private diary content was accessed.',
            'resolved_at' => now(),
        ]);
    }
}
