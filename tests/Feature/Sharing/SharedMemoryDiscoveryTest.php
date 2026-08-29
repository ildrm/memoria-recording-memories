<?php

use App\Filament\App\Pages\SharedMemories;
use App\Filament\App\Resources\ShareLinkResource\Pages\CreateShareLink;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\ShareLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Livewire\Livewire;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('paginates every registered share and searches beyond the first page', function (): void {
    Filament::setCurrentPanel('app');
    $owner = User::factory()->create(['name' => 'Trusted Sharer']);
    $recipient = User::factory()->create();

    foreach (range(1, 25) as $number) {
        $entry = Entry::factory()->for($owner, 'owner')->create([
            'title' => sprintf('Shared memory %02d', $number),
        ]);
        EntryShare::factory()->for($entry)->for($owner, 'owner')->for($recipient, 'recipient')->create([
            'created_at' => now()->subMinutes(26 - $number),
        ]);
    }

    $this->actingAs($recipient);

    Livewire::test(SharedMemories::class)
        ->assertSee('Shared memory 25')
        ->assertDontSee('Shared memory 01')
        ->call('gotoPage', 2, 'sharedPage')
        ->assertSee('Shared memory 01');

    Livewire::test(SharedMemories::class)
        ->set('search', 'Shared memory 01')
        ->assertSee('Shared memory 01')
        ->assertDontSee('Shared memory 25');
});

it('finds an older owned memory in the searchable private-link selector', function (): void {
    Filament::setCurrentPanel('app');
    $owner = User::factory()->create();
    $oldMemory = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'The oldest selectable memory',
        'occurred_at' => now()->subYears(20),
    ]);
    Entry::factory()->count(105)->for($owner, 'owner')->create([
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner);

    Livewire::test(CreateShareLink::class)
        ->fillForm([
            'entry_id' => $oldMemory->getKey(),
            'label' => 'Old memory link',
            'expires_at' => now()->addDay(),
            'include_attachments' => false,
            'track_views' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    expect(ShareLink::query()
        ->whereBelongsTo($owner, 'owner')
        ->whereBelongsTo($oldMemory)
        ->exists())->toBeTrue();
});

it('rejects a foreign memory injected into the private-link selector', function (): void {
    Filament::setCurrentPanel('app');
    $owner = User::factory()->create();
    $foreignMemory = Entry::factory()->create();

    $this->actingAs($owner);

    Livewire::test(CreateShareLink::class)
        ->fillForm([
            'entry_id' => $foreignMemory->getKey(),
            'label' => 'Injected foreign memory',
            'expires_at' => now()->addDay(),
        ])
        ->call('create')
        ->assertHasFormErrors(['entry_id']);

    expect(ShareLink::query()->where('entry_id', $foreignMemory->getKey())->exists())->toBeFalse();
});

it('applies the configured maximum expiration to the private-link picker', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 12:00:00 UTC');
    config()->set('memoria.shares.maximum_expiration_days', 30);
    Filament::setCurrentPanel('app');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    $component = Livewire::test(CreateShareLink::class);
    $expirationPicker = $component->instance()->form->getComponent('expires_at');

    expect($expirationPicker)->toBeInstanceOf(DateTimePicker::class)
        ->and(CarbonImmutable::parse($expirationPicker->getMaxDate()))
        ->toEqual(CarbonImmutable::now()->addDays(30));

    $component
        ->fillForm([
            'entry_id' => $entry->getKey(),
            'expires_at' => CarbonImmutable::now()->addDays(30)->addSecond(),
        ])
        ->call('create')
        ->assertHasFormErrors(['expires_at']);

    expect(ShareLink::query()->whereBelongsTo($entry)->exists())->toBeFalse();
});
