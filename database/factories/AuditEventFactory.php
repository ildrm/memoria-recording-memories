<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'auditable_type' => null,
            'auditable_id' => null,
            'event' => 'security.login',
            'ip_address_hash' => hash('sha256', fake()->ipv4()),
            'user_agent_hash' => hash('sha256', fake()->userAgent()),
            'metadata' => ['outcome' => 'success'],
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
