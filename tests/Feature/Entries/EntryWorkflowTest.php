<?php

use App\Actions\ForceDeleteEntry;
use App\Actions\RestoreEntryVersion;
use App\Actions\SaveEntry;
use App\Enums\EntryStatus;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('saving a memory infers ownership and captures durable versions', function (): void {
    $owner = User::factory()->create();
    $journal = Journal::factory()->for($owner, 'owner')->create();

    $entry = app(SaveEntry::class)->handle(
        owner: $owner,
        entry: null,
        attributes: [
            'user_id' => User::factory()->create()->getKey(),
            'journal_id' => $journal->getKey(),
            'title' => 'A quiet first morning',
            'body' => '<p>The private source text.</p>',
            'status' => EntryStatus::Active,
            'timezone' => 'Asia/Tehran',
        ],
    );

    expect($entry->user_id)->toBe($owner->getKey())
        ->and($entry->journal_id)->toBe($journal->getKey())
        ->and($entry->revision)->toBe(1)
        ->and($entry->versions()->count())->toBe(1)
        ->and($entry->versions()->firstOrFail()->reason)->toBe('manual_save');

    $saved = app(SaveEntry::class)->handle(
        owner: $owner,
        entry: $entry,
        attributes: ['body' => '<p>Revised private source text.</p>'],
        expectedRevision: 1,
    );

    expect($saved->revision)->toBe(2)
        ->and($saved->versions()->count())->toBe(2)
        ->and($saved->versions()->first()->body)->toContain('Revised');
});

test('optimistic concurrency prevents a stale editor from overwriting newer text', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create(['revision' => 4]);

    expect(fn () => app(SaveEntry::class)->handle(
        owner: $owner,
        entry: $entry,
        attributes: ['body' => '<p>Stale browser content.</p>'],
        expectedRevision: 3,
        autosave: true,
    ))->toThrow(ValidationException::class);

    expect($entry->refresh()->body)->not->toContain('Stale browser content');
});

test('a user cannot attach another users journal through input', function (): void {
    $owner = User::factory()->create();
    $foreignJournal = Journal::factory()->create();

    expect(fn () => app(SaveEntry::class)->handle(
        owner: $owner,
        entry: null,
        attributes: [
            'journal_id' => $foreignJournal->getKey(),
            'title' => 'Cross-account attempt',
        ],
    ))->toThrow(ValidationException::class);

    expect(Entry::query()->whereBelongsTo($owner, 'owner')->exists())->toBeFalse();
});

test('restoring an old version creates a new version instead of losing history', function (): void {
    $owner = User::factory()->create();
    $entry = app(SaveEntry::class)->handle($owner, null, [
        'title' => 'Original title',
        'body' => '<p>Original text.</p>',
    ]);
    $original = $entry->versions()->firstOrFail();

    $entry = app(SaveEntry::class)->handle(
        $owner,
        $entry,
        ['title' => 'Later title', 'body' => '<p>Later text.</p>'],
        expectedRevision: 1,
    );

    $restored = app(RestoreEntryVersion::class)->handle($entry, $original, $owner);

    expect($restored->title)->toBe('Original title')
        ->and($restored->body)->toContain('Original text')
        ->and($restored->revision)->toBe(3)
        ->and($restored->versions()->count())->toBe(3)
        ->and($restored->versions()->first()->reason)->toBe('restored_version_1');
});

test('permanent entry deletion is owner-only and removes database attachment records', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $attachment = Attachment::factory()->for($entry)->for($owner, 'owner')->create([
        'path' => "attachments/{$owner->getKey()}/{$entry->getKey()}/private-note.pdf",
    ]);
    Storage::disk('local')->put($attachment->path, 'private attachment bytes');
    $entry->delete();

    expect(fn () => app(ForceDeleteEntry::class)->handle($entry, $attacker))
        ->toThrow(AuthorizationException::class);
    expect(Entry::withTrashed()->whereKey($entry->getKey())->exists())->toBeTrue();

    app(ForceDeleteEntry::class)->handle($entry, $owner);

    expect(Entry::withTrashed()->whereKey($entry->getKey())->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($attachment->getKey())->exists())->toBeFalse()
        ->and(AuditEvent::query()
            ->where('event', 'entry.permanently_deleted')
            ->where('actor_user_id', $owner->getKey())
            ->exists())->toBeTrue()
        ->and(DB::table('stored_file_deletions')
            ->where('reason', 'entry_permanently_deleted')
            ->whereNotNull('completed_at')
            ->exists())->toBeTrue();
    Storage::disk('local')->assertMissing($attachment->path);
});
