<?php

use App\Actions\SaveEntry;
use App\Enums\EntryStatus;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\Timeline;
use App\Filament\App\Resources\EntryResource;
use App\Filament\App\Resources\EntryResource\Pages\CreateEntry;
use App\Filament\App\Resources\EntryResource\Pages\EditEntry;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('the first editor autosave creates a private draft with its owned organization and photo', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $journal = Journal::factory()->for($owner, 'owner')->create();
    $tag = Tag::factory()->for($owner, 'owner')->create();
    $person = Person::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(CreateEntry::class)
        ->fillForm(['title' => 'The first private draft'])
        ->assertHasNoFormErrors()
        ->assertSet('autosaveState', 'saved');

    $entry = Entry::query()->whereBelongsTo($owner, 'owner')->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->versions()->firstOrFail()->reason)->toBe('autosave');

    Livewire::test(EditEntry::class, ['record' => $entry->getKey()])
        ->fillForm([
            'body' => '<p>A memory that should survive autosave.</p>',
            'occurred_at' => '2026-04-12 14:30:00',
            'timezone' => 'UTC',
            'journal_id' => $journal->getKey(),
            'tags' => [$tag->getKey()],
            'people' => [$person->getKey()],
            'status' => EntryStatus::Draft->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entry->refresh()->body)->toContain('survive autosave')
        ->and($entry->journal_id)->toBe($journal->getKey())
        ->and($entry->tags()->pluck('tags.id')->all())->toBe([$tag->getKey()])
        ->and($entry->people()->pluck('people.id')->all())->toBe([$person->getKey()]);

    $this->post(route('attachments.store', $entry), [
        'file' => UploadedFile::fake()->image('first-memory.jpg'),
    ])->assertCreated();

    $photo = Attachment::query()->whereBelongsTo($entry)->firstOrFail();
    expect($photo->isOwnedBy($owner))->toBeTrue()
        ->and($photo->entry_id)->toBe($entry->getKey());
});

test('tampered tag and person ids are rejected instead of linking another users metadata', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $ownedTag = Tag::factory()->for($owner, 'owner')->create();
    $foreignTag = Tag::factory()->create();
    $ownedPerson = Person::factory()->for($owner, 'owner')->create();
    $foreignPerson = Person::factory()->create();
    $this->actingAs($owner);

    Livewire::test(EditEntry::class, ['record' => $entry->getKey()])
        ->fillForm([
            'tags' => [$ownedTag->getKey(), $foreignTag->getKey()],
            'people' => [$ownedPerson->getKey()],
        ])
        ->call('autosave')
        ->assertSet('autosaveState', 'validation')
        ->assertDispatched('entry-autosave-failed', kind: 'validation');

    expect($entry->tags()->exists())->toBeFalse()
        ->and($entry->people()->exists())->toBeFalse();

    Livewire::test(EditEntry::class, ['record' => $entry->getKey()])
        ->fillForm([
            'tags' => [$ownedTag->getKey()],
            'people' => [$ownedPerson->getKey(), $foreignPerson->getKey()],
        ])
        ->call('autosave')
        ->assertSet('autosaveState', 'validation')
        ->assertDispatched('entry-autosave-failed', kind: 'validation');

    expect($entry->tags()->exists())->toBeFalse()
        ->and($entry->people()->exists())->toBeFalse();
});

test('stale autosaves fail with a conflict and never overwrite the newer revision', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Original browser title',
        'importance' => 0,
        'revision' => 4,
    ]);
    $this->actingAs($owner);

    $this->putJson(route('entries.update', $entry), [
        'title' => 'Stale API title',
        'timezone' => 'UTC',
        'expected_revision' => 3,
        'autosave' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('revision');

    expect($entry->refresh()->title)->toBe('Original browser title')
        ->and($entry->revision)->toBe(4);

    $component = Livewire::test(EditEntry::class, ['record' => $entry->getKey()]);
    $entry->forceFill([
        'title' => 'Newer browser title',
        'revision' => 5,
    ])->save();

    $component
        ->fillForm(['title' => 'Stale Livewire title']);

    expect($component->get('autosaveState'))->toBe('conflict');

    expect($entry->refresh()->title)->toBe('Newer browser title')
        ->and($entry->revision)->toBe(5);
});

test('the editor exposes truthful saving failure session expiry and navigation protection states', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    expect(Filament::getPanel('app')->hasUnsavedChangesAlerts())->toBeTrue();

    $this->actingAs($owner)
        ->get(EntryResource::getUrl('edit', ['record' => $entry]))
        ->assertOk()
        ->assertSee('data-autosave-guard', false)
        ->assertSee('Saving privately…')
        ->assertSee('Couldn’t save · Your changes are still in this window')
        ->assertSee('Your session ended · Copy unsaved writing, then reload to continue.')
        ->assertSee('beforeunload', false)
        ->assertSee('status === 419', false);
});

test('entry bodies stay below the installed sanitizer input ceiling in every save path', function (): void {
    $owner = User::factory()->create();
    $oversizedBody = str_repeat('a', (int) config('memoria.rich_text.maximum_characters') + 1);

    expect(fn (): Entry => app(SaveEntry::class)->handle(
        owner: $owner,
        entry: null,
        attributes: ['body' => $oversizedBody],
    ))->toThrow(ValidationException::class);

    $this->actingAs($owner)
        ->postJson(route('entries.store'), [
            'body' => $oversizedBody,
            'timezone' => 'UTC',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    $this->get(EntryResource::getUrl('create'))
        ->assertOk()
        ->assertSee('Up to 125,000 characters of formatted writing.');

    expect(Entry::query()->whereBelongsTo($owner, 'owner')->exists())->toBeFalse();
});

test('naive memory times are interpreted in their IANA timezone and reject DST uncertainty', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $this->postJson(route('entries.store'), [
        'title' => 'A London morning',
        'occurred_at' => '2026-07-18 08:15:00',
        'timezone' => 'Europe/London',
    ])->assertCreated();

    $memory = Entry::query()->whereBelongsTo($owner, 'owner')->firstOrFail();
    expect($memory->occurred_at?->toIso8601String())->toBe('2026-07-18T07:15:00+00:00')
        ->and($memory->occurred_on?->format('Y-m-d'))->toBe('2026-07-18');

    $filamentMemory = Entry::factory()->for($owner, 'owner')->create(['importance' => 1]);
    Livewire::test(EditEntry::class, ['record' => $filamentMemory->getKey()])
        ->fillForm([
            'occurred_at' => '2026-07-18 08:15:00',
            'timezone' => 'Europe/London',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($filamentMemory->refresh()->occurred_at?->toIso8601String())
        ->toBe('2026-07-18T07:15:00+00:00');

    foreach (['2026-03-08 02:30:00', '2026-11-01 01:30:00'] as $uncertainTime) {
        $this->postJson(route('entries.store'), [
            'occurred_at' => $uncertainTime,
            'timezone' => 'America/New_York',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_at');
    }

    $this->postJson(route('entries.store'), [
        'title' => 'An explicitly disambiguated memory',
        'occurred_at' => '2026-11-01T01:30:00-04:00',
        'timezone' => 'America/New_York',
    ])->assertCreated();

    $disambiguated = Entry::query()
        ->whereBelongsTo($owner, 'owner')
        ->where('title', 'An explicitly disambiguated memory')
        ->firstOrFail();
    expect($disambiguated->occurred_at?->toIso8601String())->toBe('2026-11-01T05:30:00+00:00')
        ->and($disambiguated->occurred_on?->format('Y-m-d'))->toBe('2026-11-01');
});

test('timeline and calendar use the memory date and never reveal another users entries', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $memory = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Owner timeline memory',
        'occurred_at' => CarbonImmutable::parse('2026-05-17 08:00:00 UTC'),
        'timezone' => 'UTC',
    ]);
    $foreignMemory = Entry::factory()->for($stranger, 'owner')->create([
        'title' => 'Foreign private timeline memory',
        'occurred_at' => CarbonImmutable::parse('2026-05-17 09:00:00 UTC'),
        'timezone' => 'UTC',
    ]);
    $this->actingAs($owner);

    $this->get(Timeline::getUrl())
        ->assertOk()
        ->assertSee($memory->title)
        ->assertDontSee($foreignMemory->title);

    $this->get(Calendar::getUrl(['month' => '2026-05']))
        ->assertOk()
        ->assertSee($memory->title)
        ->assertDontSee($foreignMemory->title);

    $this->get(EntryResource::getUrl('edit', ['record' => $foreignMemory]))
        ->assertNotFound();
});
