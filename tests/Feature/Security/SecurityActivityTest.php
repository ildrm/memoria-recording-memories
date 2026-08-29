<?php

use App\Filament\App\Resources\SecurityActivityResource;
use App\Filament\App\Resources\SecurityActivityResource\Pages\ListSecurityActivities;
use App\Models\AuditEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('security activity shows only recognized events concerning the signed-in account', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    AuditEvent::factory()->create([
        'actor_user_id' => $owner->getKey(),
        'auditable_type' => $owner->getMorphClass(),
        'auditable_id' => $owner->getKey(),
        'event' => 'authentication.login',
        'metadata' => ['diagnostic_marker' => 'never-render-this-owner-metadata'],
    ]);
    AuditEvent::factory()->create([
        'actor_user_id' => null,
        'auditable_type' => $owner->getMorphClass(),
        'auditable_id' => $owner->getKey(),
        'event' => 'account.password_changed',
    ]);
    AuditEvent::factory()->create([
        'actor_user_id' => $otherUser->getKey(),
        'auditable_type' => $otherUser->getMorphClass(),
        'auditable_id' => $otherUser->getKey(),
        'event' => 'account.totp_enabled',
    ]);
    AuditEvent::factory()->create([
        'actor_user_id' => $owner->getKey(),
        'auditable_type' => $owner->getMorphClass(),
        'auditable_id' => $owner->getKey(),
        'event' => 'publication.published',
    ]);

    $this->actingAs($owner->refresh())
        ->get('/app/security-activity')
        ->assertOk()
        ->assertSee('Signed in')
        ->assertSee('Password changed')
        ->assertDontSee('Two-factor authentication enabled')
        ->assertDontSee('publication.published')
        ->assertDontSee('never-render-this-owner-metadata');
});

test('security activity is owner scoped and paginated to ten events by default', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $events = collect();

    foreach (range(1, 12) as $minutesAgo) {
        $events->push(AuditEvent::factory()->create([
            'actor_user_id' => $owner->getKey(),
            'auditable_type' => $owner->getMorphClass(),
            'auditable_id' => $owner->getKey(),
            'event' => 'authentication.login',
            'occurred_at' => now()->subMinutes($minutesAgo),
        ]));
    }

    $foreignEvent = AuditEvent::factory()->create([
        'actor_user_id' => $otherUser->getKey(),
        'auditable_type' => $otherUser->getMorphClass(),
        'auditable_id' => $otherUser->getKey(),
        'event' => 'authentication.login',
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner->refresh());

    Livewire::test(ListSecurityActivities::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($events->take(10), inOrder: true)
        ->assertCanNotSeeTableRecords($events->skip(10)->push($foreignEvent));
});

test('settings links to the dedicated security activity history', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner->refresh())
        ->get('/app/settings')
        ->assertOk()
        ->assertSee('Security activity')
        ->assertSee(SecurityActivityResource::getUrl(), escape: false);
});
