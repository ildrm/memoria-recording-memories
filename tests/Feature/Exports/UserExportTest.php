<?php

use App\Enums\ExportStatus;
use App\Jobs\ExpireUserExports;
use App\Jobs\GenerateUserExport;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\EntryVersion;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\PublicationVersion;
use App\Models\Reaction;
use App\Models\Reminder;
use App\Models\Report;
use App\Models\ShareLink;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\ExportReadyNotification;
use App\Services\UserExportArchiveBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('a queued export contains only its owners records and sends a ready notification', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $journal = Journal::factory()->for($owner, 'owner')->create(['name' => 'Owner journal']);
    Entry::factory()->forJournal($journal)->create([
        'title' => 'Owner-only memory',
        'body' => '<p>Fictional private export text.</p>',
    ]);
    Entry::factory()->for($otherUser, 'owner')->create([
        'title' => 'Foreign memory that must not be exported',
    ]);
    $export = Export::factory()->for($owner, 'owner')->create([
        'options' => [
            'formats' => ['json', 'markdown'],
            'include_attachments' => false,
        ],
    ]);

    app()->call([new GenerateUserExport((int) $export->getKey()), 'handle']);
    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->path)->not->toBeNull()
        ->and($export->expires_at?->isFuture())->toBeTrue();
    Storage::disk('local')->assertExists($export->path);
    Notification::assertSentTo($owner, ExportReadyNotification::class);
    Notification::assertNotSentTo($otherUser, ExportReadyNotification::class);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($export->path)))->toBeTrue();
    $entryJson = $zip->getFromName('entries/'.$owner->entries()->firstOrFail()->getKey().'.json');
    expect($entryJson)->toContain('Owner-only memory')
        ->and($entryJson)->not->toContain('Foreign memory');
    $zip->close();
});

test('secure downloads are owner only and expired archives are removed', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $export = Export::factory()->for($owner, 'owner')->ready()->create([
        'path' => 'exports/'.$owner->getKey().'/safe-export.zip',
        'filename' => "memoria-export\r\nunsafe.zip",
    ]);
    Storage::disk('local')->put($export->path, 'fictional-zip-content');

    $this->actingAs($attacker)
        ->get(route('exports.download', $export))
        ->assertForbidden();

    $download = $this->actingAs($owner)
        ->get(route('exports.download', $export))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store')
        ->toContain('max-age=0');

    $export->forceFill(['expires_at' => now()->subSecond()])->save();
    app(ExpireUserExports::class)->handle();

    expect($export->refresh()->status)->toBe(ExportStatus::Expired)
        ->and($export->path)->toBeNull();
    Storage::disk('local')->assertMissing('exports/'.$owner->getKey().'/safe-export.zip');

    $this->actingAs($owner)
        ->get(route('exports.download', $export))
        ->assertForbidden();
});

test('a deleted in-flight export cannot orphan its provisional archive', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $export = Export::factory()->for($owner, 'owner')->create([
        'options' => ['formats' => ['json'], 'include_attachments' => false],
    ]);
    $realBuilder = app(UserExportArchiveBuilder::class);
    $provisionalPath = null;
    $interleavingBuilder = Mockery::mock(UserExportArchiveBuilder::class);
    $interleavingBuilder->shouldReceive('build')
        ->once()
        ->andReturnUsing(function (Export $buildingExport, User $buildingOwner) use ($realBuilder, &$provisionalPath): array {
            $result = $realBuilder->build($buildingExport, $buildingOwner);
            $provisionalPath = $result['path'];
            $buildingExport->delete();

            return $result;
        });
    app()->instance(UserExportArchiveBuilder::class, $interleavingBuilder);

    expect(fn () => app()->call([new GenerateUserExport((int) $export->getKey()), 'handle']))
        ->toThrow(ModelNotFoundException::class);

    expect($provisionalPath)->toBeString();
    Storage::disk('local')->assertMissing((string) $provisionalPath);
    $this->assertDatabaseHas('stored_file_deletions', [
        'reason' => 'user_export_generation_aborted',
    ]);
});

test('the export request endpoint derives ownership from the authenticated account', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $injectedUser = User::factory()->create();

    $response = $this->actingAs($owner)->postJson(route('exports.store'), [
        'user_id' => $injectedUser->getKey(),
        'formats' => ['json'],
        'include_attachments' => false,
    ]);

    $response->assertAccepted();
    $export = Export::query()->findOrFail($response->json('data.id'));

    expect($export->user_id)->toBe($owner->getKey())
        ->and($export->status)->toBe(ExportStatus::Pending)
        ->and($export->options['formats'])->toBe(['json']);
});

test('the manifest covers owned history sharing community and social metadata without secrets', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    EntryVersion::factory()->create([
        'entry_id' => $entry->getKey(),
        'user_id' => $owner->getKey(),
        'body' => '<p>Earlier private version.</p>',
    ]);
    Tag::factory()->for($owner, 'owner')->create(['name' => 'Standalone owner tag']);
    Person::factory()->for($owner, 'owner')->create(['notes' => 'Private relationship notes.']);
    EntryShare::factory()->create([
        'entry_id' => $entry->getKey(),
        'shared_by_user_id' => $owner->getKey(),
        'shared_with_user_id' => $recipient->getKey(),
    ]);
    ShareLink::factory()->passwordProtected('never-export-this-share-password')->create([
        'entry_id' => $entry->getKey(),
        'user_id' => $owner->getKey(),
    ]);
    Reminder::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    PublicationVersion::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'body' => '<p>Earlier public draft.</p>',
    ]);
    Comment::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'ip_hash' => 'never-export-this-ip-hash',
    ]);
    Reaction::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
    ]);
    Report::factory()->create([
        'publication_id' => $publication->getKey(),
        'reporter_user_id' => $owner->getKey(),
        'moderation_notes' => 'never-export-internal-moderation-notes',
    ]);
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'access_token' => 'never-export-access-token',
        'refresh_token' => 'never-export-refresh-token',
        'metadata' => [
            'page_id' => '123456789',
            'private_provider_payload' => 'never-export-provider-account-metadata',
        ],
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => $account->provider,
        'idempotency_key' => hash('sha256', 'never-export-idempotency-source'),
        'provider_metadata' => ['raw' => 'never-export-provider-post-metadata'],
    ]);
    SocialPostFailure::factory()->create([
        'social_post_id' => $post->getKey(),
        'context' => ['raw_response' => 'never-export-failure-context'],
    ]);
    Tag::factory()->for(User::factory(), 'owner')->create(['name' => 'Foreign tag marker']);
    $export = Export::factory()->for($owner, 'owner')->create([
        'options' => ['formats' => ['json'], 'include_attachments' => false],
    ]);

    app()->call([new GenerateUserExport((int) $export->getKey()), 'handle']);
    $export->refresh();

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($export->path)))->toBeTrue();
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $metadata = collect([
        'metadata/entry-versions.json',
        'metadata/tags.json',
        'metadata/people.json',
        'metadata/publication-versions.json',
        'metadata/shares.json',
        'metadata/reminders.json',
        'metadata/community.json',
        'metadata/social.json',
    ])->map(fn (string $path): string => (string) $zip->getFromName($path))->implode("\n");
    $zip->close();

    expect($manifest['counts'])->toMatchArray([
        'entry_versions' => 1,
        'tags' => 1,
        'people' => 1,
        'publication_versions' => 1,
        'shares' => ['entry_shares_sent' => 1, 'entry_shares_received' => 0, 'share_links' => 1],
        'reminders' => 1,
        'community' => ['comments' => 1, 'reactions' => 1, 'reports' => 1],
        'social' => ['accounts' => 1, 'publication_targets' => 1, 'posts' => 1, 'failures' => 1],
    ])->and($metadata)->toContain('Earlier private version.')
        ->and($metadata)->toContain('Earlier public draft.')
        ->and($metadata)->toContain('Standalone owner tag')
        ->and($metadata)->toContain('Private relationship notes.')
        ->and($metadata)->toContain('123456789')
        ->and($metadata)->not->toContain('Foreign tag marker')
        ->and($metadata)->not->toContain('never-export-this-share-password')
        ->and($metadata)->not->toContain('never-export-this-ip-hash')
        ->and($metadata)->not->toContain('never-export-internal-moderation-notes')
        ->and($metadata)->not->toContain('never-export-access-token')
        ->and($metadata)->not->toContain('never-export-refresh-token')
        ->and($metadata)->not->toContain('never-export-provider-account-metadata')
        ->and($metadata)->not->toContain('never-export-provider-post-metadata')
        ->and($metadata)->not->toContain('never-export-failure-context')
        ->and($metadata)->not->toContain(hash('sha256', 'never-export-idempotency-source'));
});

test('large historical metadata is exported in ordered primary key chunks', function (): void {
    Storage::fake('local');
    config()->set('memoria.exports.chunk_size', 10);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $entryVersions = EntryVersion::factory()
        ->count(11)
        ->sequence(fn (Sequence $sequence): array => ['version' => $sequence->index + 1])
        ->create([
            'entry_id' => $entry->getKey(),
            'user_id' => $owner->getKey(),
        ]);
    $publicationVersions = PublicationVersion::factory()
        ->count(11)
        ->sequence(fn (Sequence $sequence): array => ['version' => $sequence->index + 1])
        ->create([
            'publication_id' => $publication->getKey(),
            'user_id' => $owner->getKey(),
        ]);
    Journal::factory()->count(11)->for($owner, 'owner')->create();
    foreach (range(1, 11) as $tagNumber) {
        fake()->unique(true);
        Tag::factory()->for($owner, 'owner')->create([
            'name' => 'history-tag-'.$tagNumber,
        ]);
    }
    Person::factory()->count(11)->for($owner, 'owner')->create();
    ShareLink::factory()->count(11)->for($owner, 'owner')->create([
        'entry_id' => $entry->getKey(),
    ]);
    Reminder::factory()->count(11)->for($owner, 'owner')->create();
    Comment::factory()->count(11)->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
    ]);
    $socialAccount = SocialAccount::factory()->for($owner, 'owner')->create();
    $target = PublicationTarget::factory()->forSocialAccount($publication, $socialAccount)->create();
    $socialPosts = SocialPost::factory()->count(11)->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $socialAccount->getKey(),
        'provider' => $socialAccount->provider,
    ]);
    $socialPosts->each(fn (SocialPost $post) => SocialPostFailure::factory()->create([
        'social_post_id' => $post->getKey(),
    ]));
    $export = Export::factory()->for($owner, 'owner')->create([
        'options' => ['formats' => ['json'], 'include_attachments' => false],
    ]);
    $chunkedTables = [
        'journals',
        'entry_versions',
        'publication_versions',
        'tags',
        'people',
        'share_links',
        'reminders',
        'comments',
        'social_posts',
        'social_post_failures',
    ];
    $queryCounts = array_fill_keys($chunkedTables, 0);
    DB::listen(function (QueryExecuted $query) use (&$queryCounts, $chunkedTables): void {
        $sql = strtolower($query->sql);

        foreach ($chunkedTables as $table) {
            if (str_contains($sql, 'from "'.$table.'"') || str_contains($sql, 'from `'.$table.'`')) {
                $queryCounts[$table]++;
            }
        }
    });

    $result = app(UserExportArchiveBuilder::class)->build($export, $owner);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($result['path'])))->toBeTrue();
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entryVersionMetadata = json_decode((string) $zip->getFromName('metadata/entry-versions.json'), true, flags: JSON_THROW_ON_ERROR);
    $publicationVersionMetadata = json_decode((string) $zip->getFromName('metadata/publication-versions.json'), true, flags: JSON_THROW_ON_ERROR);
    $shares = json_decode((string) $zip->getFromName('metadata/shares.json'), true, flags: JSON_THROW_ON_ERROR);
    $community = json_decode((string) $zip->getFromName('metadata/community.json'), true, flags: JSON_THROW_ON_ERROR);
    $social = json_decode((string) $zip->getFromName('metadata/social.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();

    expect($manifest['counts'])->toMatchArray([
        'journals' => 11,
        'entry_versions' => 11,
        'tags' => 11,
        'people' => 11,
        'publication_versions' => 11,
        'shares' => ['entry_shares_sent' => 0, 'entry_shares_received' => 0, 'share_links' => 11],
        'reminders' => 11,
        'community' => ['comments' => 11, 'reactions' => 0, 'reports' => 0],
        'social' => ['accounts' => 1, 'publication_targets' => 1, 'posts' => 11, 'failures' => 11],
    ])->and(array_column($entryVersionMetadata, 'id'))->toBe($entryVersions->modelKeys())
        ->and(array_column($publicationVersionMetadata, 'id'))->toBe($publicationVersions->modelKeys())
        ->and(array_keys($shares))->toBe(['entry_shares_sent', 'entry_shares_received', 'share_links'])
        ->and(array_keys($community))->toBe(['comments', 'reactions', 'reports'])
        ->and(array_keys($social))->toBe(['accounts', 'publication_targets', 'posts', 'failures'])
        ->and(array_column($social['posts'], 'id'))->toBe($socialPosts->modelKeys());

    foreach ($chunkedTables as $table) {
        expect($queryCounts[$table], $table)->toBeGreaterThanOrEqual(2);
    }
});
