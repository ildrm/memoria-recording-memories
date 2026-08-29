<?php

use App\Actions\RestoreEntryVersion;
use App\Filament\App\Resources\EntryResource\Pages\EditEntry;
use App\Filament\App\Resources\EntryResource\RelationManagers\VersionsRelationManager;
use App\Models\Entry;
use App\Models\EntryVersion;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('lets the owner inspect a private snapshot and restore it as a new revision', function (): void {
    Filament::setCurrentPanel('app');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Current memory title',
        'body' => '<p>Current memory writing.</p>',
        'revision' => 2,
    ]);
    $version = EntryVersion::factory()->for($entry)->for($owner, 'owner')->create([
        'version' => 1,
        'title' => 'Earlier private title',
        'body' => '<p>Earlier private writing.</p>',
        'reason' => 'manual_save',
    ]);

    $this->actingAs($owner);

    Livewire::test(VersionsRelationManager::class, [
        'ownerRecord' => $entry,
        'pageClass' => EditEntry::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$version])
        ->mountAction(TestAction::make('inspect')->table($version))
        ->assertMountedActionModalSee('Earlier private title')
        ->assertMountedActionModalSee('Earlier private writing');

    Livewire::test(VersionsRelationManager::class, [
        'ownerRecord' => $entry,
        'pageClass' => EditEntry::class,
    ])
        ->callAction(TestAction::make('restore')->table($version))
        ->assertNotified();

    expect($entry->refresh())
        ->title->toBe('Earlier private title')
        ->body->toContain('Earlier private writing')
        ->revision->toBe(3)
        ->and($entry->versions()->count())->toBe(3)
        ->and($entry->versions()->where('version', 2)->firstOrFail())
        ->title->toBe('Current memory title')
        ->reason->toBe('before_version_restore')
        ->and($entry->versions()->latest('version')->firstOrFail()->reason)->toBe('restored_version_1');
});

it('denies cross-owner restore and every attempt to delete immutable history', function (): void {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Owner current text',
        'revision' => 2,
    ]);
    $version = EntryVersion::factory()->for($entry)->for($owner, 'owner')->create([
        'version' => 1,
        'title' => 'Owner historical text',
    ]);

    expect(Gate::forUser($attacker)->allows('restore', $version))->toBeFalse()
        ->and(Gate::forUser($attacker)->allows('delete', $version))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $version))->toBeFalse();

    expect(fn (): Entry => app(RestoreEntryVersion::class)->handle($entry, $version, $attacker))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($attacker)
        ->post(route('entries.versions.restore', [$entry, $version]))
        ->assertForbidden();

    expect($entry->refresh()->title)->toBe('Owner current text')
        ->and($entry->revision)->toBe(2)
        ->and(EntryVersion::query()->whereKey($version)->exists())->toBeTrue();
});
