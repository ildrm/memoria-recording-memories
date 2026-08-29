<?php

use App\Actions\ModeratePublicPublication;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\PublicationResource as AdminPublicationResource;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('administrative roles can enter operations but cannot read private diaries', function (): void {
    $owner = User::factory()->create();
    $administrator = User::factory()->create();
    $administrator->assignRole(RoleName::Administrator);
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Do not reveal this private title',
        'body' => '<p>Do not reveal this private diary body.</p>',
    ]);
    $attachment = Attachment::factory()->for($entry)->create();

    expect($administrator->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('view', $entry))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('view', $attachment))->toBeFalse()
        ->and(class_exists('App\\Filament\\Admin\\Resources\\EntryResource'))->toBeFalse()
        ->and(Filament::getPanel('admin')->getGlobalSearchProvider())->toBeNull();

    $this->actingAs($administrator->refresh())
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Do not reveal this private title')
        ->assertDontSee('Do not reveal this private diary body');
});

test('admin publication queries expose only already public snapshots', function (): void {
    $owner = User::factory()->create();
    $administrator = User::factory()->create();
    $administrator->assignRole(RoleName::Administrator);
    Publication::factory()->for($owner, 'owner')->create([
        'title' => 'Unpublished moderation secret',
    ]);
    $public = Publication::factory()->for($owner, 'owner')->published()->create([
        'title' => 'Already public moderation subject',
    ]);
    PublicationTarget::factory()->publishedWebsite($public)->create();

    $visibleIds = AdminPublicationResource::getEloquentQuery()->pluck('id');

    expect($visibleIds->all())->toBe([$public->getKey()]);

    $this->actingAs($administrator->refresh())
        ->get('/admin/publications')
        ->assertOk()
        ->assertSee('Already public moderation subject')
        ->assertDontSee('Unpublished moderation secret');
});

test('ordinary and disabled accounts cannot enter the admin panel', function (): void {
    $ordinaryUser = User::factory()->create();
    $disabledAdministrator = User::factory()->disabled()->create();
    $disabledAdministrator->assignRole(RoleName::Administrator);

    expect($ordinaryUser->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($disabledAdministrator->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->actingAs($ordinaryUser->refresh())->get('/admin')->assertForbidden();
    $this->actingAs($disabledAdministrator->refresh())->get('/admin')->assertForbidden();
});

test('moderation removes only a public snapshot and records the moderator', function (): void {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole(RoleName::Moderator);
    $privateEntry = Entry::factory()->for($owner, 'owner')->create([
        'body' => '<p>Private source remains untouched.</p>',
    ]);
    $publication = Publication::factory()->fromEntry($privateEntry)->published()->create([
        'body' => '<p>Public snapshot under moderation.</p>',
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();

    $moderated = app(ModeratePublicPublication::class)->handle(
        $publication,
        $moderator,
        'Fictional policy test reason',
    );

    expect($moderated->status)->toBe(PublicationStatus::Unpublished)
        ->and($moderated->isPubliclyVisible())->toBeFalse()
        ->and($moderated->versions()->where('reason', 'moderated_unpublished')->exists())->toBeTrue()
        ->and($privateEntry->refresh()->body)->toBe('<p>Private source remains untouched.</p>')
        ->and(AuditEvent::query()
            ->where('event', 'publication.moderated_unpublished')
            ->where('actor_user_id', $moderator->getKey())
            ->where('auditable_id', $publication->getKey())
            ->exists())->toBeTrue();
});
