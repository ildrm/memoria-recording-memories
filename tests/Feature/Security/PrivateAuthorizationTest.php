<?php

use App\Actions\CreatePublicationDraft;
use App\Actions\CreateShareLink;
use App\Actions\SaveEntry;
use App\Enums\RoleName;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\EntryVersion;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\ShareLink;
use App\Models\SocialAccount;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

test('private records deny unrelated users and privileged staff', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $administrator = User::factory()->withRole(RoleName::SuperAdministrator)->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    $privateRecords = [
        Journal::factory()->for($owner, 'owner')->create(),
        $entry,
        EntryVersion::factory()->for($entry)->create(),
        Attachment::factory()->for($entry)->create(),
        Tag::factory()->for($owner, 'owner')->create(),
        Person::factory()->for($owner, 'owner')->create(),
        ShareLink::factory()->for($entry)->create(),
        Export::factory()->for($owner, 'owner')->create(),
        Publication::factory()->fromEntry($entry)->create(),
        SocialAccount::factory()->for($owner, 'owner')->create(),
    ];

    foreach ([$stranger, $administrator] as $unauthorizedUser) {
        foreach ($privateRecords as $record) {
            expect(Gate::forUser($unauthorizedUser)->allows('view', $record))
                ->toBeFalse('Unexpected access to '.$record::class.' for user '.$unauthorizedUser->getKey());
        }
    }
});

test('owner scoped actions reject an entry id owned by another account', function (): void {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    expect(fn () => app(SaveEntry::class)->handle(
        owner: $attacker,
        entry: $entry,
        attributes: ['title' => 'Stolen title'],
        expectedRevision: $entry->revision,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(CreatePublicationDraft::class)->handle($entry, $attacker))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(CreateShareLink::class)->handle($entry, $attacker))
        ->toThrow(AuthorizationException::class);

    expect($entry->refresh()->title)->not->toBe('Stolen title');
    expect(Publication::query()->whereBelongsTo($attacker, 'owner')->exists())->toBeFalse();
    expect(ShareLink::query()->whereBelongsTo($attacker, 'owner')->exists())->toBeFalse();
});

test('an explicit active share grants view only and never mutation', function (): void {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    EntryShare::factory()->create([
        'entry_id' => $entry->getKey(),
        'shared_by_user_id' => $owner->getKey(),
        'shared_with_user_id' => $recipient->getKey(),
    ]);

    expect(Gate::forUser($recipient)->allows('view', $entry))->toBeTrue()
        ->and(Gate::forUser($recipient)->allows('update', $entry))->toBeFalse()
        ->and(Gate::forUser($recipient)->allows('delete', $entry))->toBeFalse()
        ->and(Gate::forUser($recipient)->allows('share', $entry))->toBeFalse()
        ->and(Gate::forUser($recipient)->allows('publish', $entry))->toBeFalse();
});
