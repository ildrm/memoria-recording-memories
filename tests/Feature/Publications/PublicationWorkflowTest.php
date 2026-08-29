<?php

use App\Actions\ArchivePublication;
use App\Actions\ConfirmPublicationPrivacyReview;
use App\Actions\CreateIndependentPublicationDraft;
use App\Actions\CreatePublicationDraft;
use App\Actions\PublishPublication;
use App\Actions\RecordPublicationPreview;
use App\Actions\RestoreArchivedPublication;
use App\Actions\UnpublishPublication;
use App\Actions\UpdatePublicationDraft;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialPostStatus;
use App\Filament\App\Resources\PublicationResource\Pages\CreatePublication;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\PublicationPrivacyReview;
use App\Services\PublicationWorkflowConfirmation;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('independent drafts created in the panel use the guarded domain workflow', function (): void {
    $owner = User::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $this->actingAs($owner);

    Livewire::test(CreatePublication::class)
        ->fillForm([
            'title' => 'A deliberate public essay',
            'slug' => 'a-deliberate-public-essay',
            'excerpt' => 'Written independently from the private diary.',
            'body' => '<p>Only details intended for a public audience.</p>',
            'topics' => [' Reflection ', '<b>Practice</b>', 'reflection'],
            'comments_enabled' => true,
            'reactions_enabled' => false,
            'search_engine_indexing' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $publication = Publication::query()
        ->whereBelongsTo($owner, 'owner')
        ->where('slug', 'a-deliberate-public-essay')
        ->firstOrFail();

    expect($publication->source_entry_id)->toBeNull()
        ->and($publication->status)->toBe(PublicationStatus::Draft)
        ->and($publication->topics)->toBe(['Reflection', 'Practice'])
        ->and($publication->versions()->where('reason', 'created_independently')->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('event', 'publication.draft_created')
            ->where('auditable_id', $publication->getKey())
            ->exists())->toBeTrue();

    expect(fn () => app(CreateIndependentPublicationDraft::class)->handle($owner, [
        'title' => 'Invalid address',
        'slug' => '../private-memory',
        'body' => '<p>Public text.</p>',
    ]))->toThrow(ValidationException::class);
});

test('opening a preview is read only and confirmation requires an explicit mutation', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);

    $previewEvents = fn (): int => AuditEvent::query()
        ->where('event', PublicationWorkflowConfirmation::PREVIEW_EVENT)
        ->where('auditable_id', $publication->getKey())
        ->count();

    $this->actingAs($owner)
        ->get(route('app.publications.preview', $publication))
        ->assertOk()
        ->assertSee('Confirm I inspected this exact preview')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    expect($previewEvents())->toBe(0);

    $this->post(route('app.publications.preview.store', $publication))
        ->assertRedirect(route('app.publications.preview', $publication));

    expect($previewEvents())->toBe(1);

    $this->get(route('app.publications.preview', $publication))
        ->assertOk()
        ->assertSee('Exact preview confirmed')
        ->assertDontSee('Confirm I inspected this exact preview');
});

test('archiving safely cancels local delivery and can be restored only as a private draft', function (): void {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'status' => PublicationStatus::Scheduled,
        'scheduled_at' => now()->addHour(),
        'privacy_reviewed_at' => now(),
    ]);
    $target = PublicationTarget::factory()->for($publication)->create([
        'user_id' => $owner->getKey(),
        'target_key' => 'website',
        'type' => PublicationTargetType::Website,
        'status' => PublicationTargetStatus::Scheduled,
        'scheduled_at' => now()->addHour(),
    ]);
    $socialPost = SocialPost::factory()->for($publication)->create([
        'user_id' => $owner->getKey(),
        'status' => SocialPostStatus::Scheduled,
        'scheduled_at' => now()->addHour(),
    ]);

    $archived = app(ArchivePublication::class)->handle($publication, $owner);

    expect($archived->status)->toBe(PublicationStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->scheduled_at)->toBeNull()
        ->and($archived->privacy_reviewed_at)->toBeNull()
        ->and($target->refresh()->status)->toBe(PublicationTargetStatus::Cancelled)
        ->and($socialPost->refresh()->status)->toBe(SocialPostStatus::Cancelled)
        ->and($archived->versions()->where('reason', 'archived')->exists())->toBeTrue()
        ->and($archived->trashed())->toBeFalse();

    expect(fn () => app(RestoreArchivedPublication::class)->handle($archived, $attacker))
        ->toThrow(AuthorizationException::class);

    $restored = app(RestoreArchivedPublication::class)->handle($archived, $owner);

    expect($restored->status)->toBe(PublicationStatus::Draft)
        ->and($restored->archived_at)->toBeNull()
        ->and($restored->privacy_reviewed_at)->toBeNull()
        ->and($restored->versions()->where('reason', 'restored_from_archive')->exists())->toBeTrue();
});

test('publishing creates and serves an independent reviewed snapshot', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $owner->profile()->update([
        'username' => 'ari-moon',
        'display_name' => 'Ari Moon',
        'is_public' => true,
    ]);
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Private mountain notes',
        'body' => '<p>Private detail: the key is under the blue stone.</p>',
        'location_name' => 'Exact private cabin',
    ]);

    $publication = app(CreatePublicationDraft::class)->handle($entry, $owner);

    expect($publication->source_entry_id)->toBe($entry->getKey())
        ->and($publication->body)->toBe($entry->body)
        ->and($publication->status)->toBe(PublicationStatus::Draft)
        ->and($publication->versions()->count())->toBe(1);

    $publication->forceFill([
        'title' => 'What the mountain taught me',
        'slug' => 'what-the-mountain-taught-me',
        'excerpt' => 'A safe public summary.',
        'body' => '<p>A calm public reflection.</p>',
    ])->save();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication->refresh(), $owner);
    app(RecordPublicationPreview::class)->handle($publication->refresh(), $owner);

    $published = app(PublishPublication::class)->handle(
        publication: $publication,
        owner: $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    );

    expect($published->status)->toBe(PublicationStatus::Published)
        ->and($published->privacy_reviewed_at)->not->toBeNull()
        ->and($published->targets()->count())->toBe(1)
        ->and($published->targets()->firstOrFail()->status)->toBe(PublicationTargetStatus::Published)
        ->and($published->versions()->where('reason', 'published')->exists())->toBeTrue()
        ->and($entry->refresh()->body)->toContain('key is under the blue stone');

    $this->get(route('publications.show', [
        'username' => 'ari-moon',
        'publicationSlug' => $published->slug,
    ]))
        ->assertOk()
        ->assertSee('What the mountain taught me')
        ->assertSee('A calm public reflection')
        ->assertDontSee('key is under the blue stone');
});

test('the domain rejects publishing without review preview or a target', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();

    expect(fn () => app(PublishPublication::class)->handle(
        $publication,
        $owner,
        privacyReviewConfirmed: false,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(PublishPublication::class)->handle(
        $publication,
        $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: false,
        publishToWebsite: true,
        socialProviders: [],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(PublishPublication::class)->handle(
        $publication,
        $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: false,
        socialProviders: [],
    ))->toThrow(ValidationException::class);

    expect($publication->refresh()->status)->toBe(PublicationStatus::Draft)
        ->and($publication->published_at)->toBeNull();
});

test('draft and unpublished snapshots never resolve through public routes', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'private-writer', 'is_public' => true]);
    $draft = Publication::factory()->for($owner, 'owner')->create(['slug' => 'still-a-draft']);

    $this->get(route('publications.show', [
        'username' => 'private-writer',
        'publicationSlug' => $draft->slug,
    ]))->assertNotFound();

    $published = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'briefly-public',
    ]);
    app(UnpublishPublication::class)->handle($published, $owner);

    $this->get(route('publications.show', [
        'username' => 'private-writer',
        'publicationSlug' => $published->slug,
    ]))->assertNotFound();
});

test('public rich text is sanitized before it reaches a reader', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'safe-author', 'is_public' => true]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'safe-story',
        'body' => '<p style="position:fixed;inset:0">Visible paragraph</p><script>alert("diary")</script><img src="https://tracker.example/pixel" onerror=alert(1)><a href="https://example.com" target="_blank">Safe link</a><dialog open>Page trap</dialog><portal src="https://evil.example"></portal><template shadowrootmode="open">Shadow trap</template><marquee>Moving trap</marquee><xmp>Legacy trap</xmp><plaintext>Parser trap',
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();

    $this->get(route('publications.show', [
        'username' => 'safe-author',
        'publicationSlug' => $publication->slug,
    ]))
        ->assertOk()
        ->assertSee('Visible paragraph')
        ->assertSee('Safe link')
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertDontSee('alert("diary")', false)
        ->assertDontSee('onerror=', false)
        ->assertDontSee('style=', false)
        ->assertDontSee('tracker.example', false)
        ->assertDontSee('<img', false)
        ->assertDontSee('<dialog', false)
        ->assertDontSee('<portal', false)
        ->assertDontSee('<template', false)
        ->assertDontSee('<marquee', false)
        ->assertDontSee('<xmp', false)
        ->assertDontSee('<plaintext', false);
});

test('public drafts cannot exceed the installed safe renderer input boundary', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'body' => '<p>Existing safe public text.</p>',
    ]);

    expect(fn () => app(UpdatePublicationDraft::class)->handle($publication, $owner, [
        'body' => str_repeat('a', (int) config('memoria.rich_text.maximum_characters') + 1),
    ]))->toThrow(ValidationException::class);

    expect($publication->refresh()->body)->toBe('<p>Existing safe public text.</p>')
        ->and($publication->versions()->count())->toBe(0);
});

test('privacy review never inspects a source entry outside the publication owner boundary', function (): void {
    $owner = User::factory()->create();
    $foreignEntry = Entry::factory()->create([
        'location_name' => 'Foreign private location',
        'latitude' => 35.7,
        'longitude' => 51.4,
    ]);
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'source_entry_id' => $foreignEntry->getKey(),
        'body' => '<p>An independent safe draft.</p>',
    ]);

    expect(app(PublicationPrivacyReview::class)->warnings($publication))
        ->not->toContain(['code' => 'location', 'message' => 'The source memory contains location information. Confirm it is not present in the public version.']);
});

test('editing a live snapshot withdraws it until the changed content is reviewed and republished', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'careful-editor', 'is_public' => true]);
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'body' => '<p>The private source is unchanged.</p>',
    ]);
    $publication = Publication::factory()->fromEntry($entry)->published()->create([
        'slug' => 'reviewed-version',
        'body' => '<p>The previously reviewed public text.</p>',
    ]);
    PublicationTarget::factory()->for($publication)->for($owner, 'owner')->create([
        'status' => PublicationTargetStatus::Published,
    ]);

    $this->get(route('publications.show', [
        'username' => 'careful-editor',
        'publicationSlug' => 'reviewed-version',
    ]))->assertOk()->assertSee('previously reviewed public text');

    $updated = app(UpdatePublicationDraft::class)->handle($publication, $owner, [
        'title' => $publication->title,
        'slug' => $publication->slug,
        'excerpt' => $publication->excerpt,
        'body' => '<p>A changed version requiring another privacy review.</p>',
        'comments_enabled' => $publication->comments_enabled,
        'reactions_enabled' => $publication->reactions_enabled,
        'search_engine_indexing' => $publication->search_engine_indexing,
    ]);

    expect($updated->status)->toBe(PublicationStatus::Unpublished)
        ->and($updated->privacy_reviewed_at)->toBeNull()
        ->and($updated->targets()->firstOrFail()->status)->toBe(PublicationTargetStatus::Cancelled)
        ->and($updated->versions()->where('reason', 'edited_and_withdrawn')->exists())->toBeTrue()
        ->and($entry->refresh()->body)->toBe('<p>The private source is unchanged.</p>');

    $this->get(route('publications.show', [
        'username' => 'careful-editor',
        'publicationSlug' => 'reviewed-version',
    ]))->assertNotFound();
});

test('review and preview confirmations are bound to the exact public version', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'title' => 'A carefully redacted memory',
        'body' => '<p>The first public version.</p>',
    ]);

    expect(fn () => app(RecordPublicationPreview::class)->handle($publication, $owner))
        ->toThrow(ValidationException::class);

    app(ConfirmPublicationPrivacyReview::class)->handle($publication->refresh(), $owner);
    app(RecordPublicationPreview::class)->handle($publication->refresh(), $owner);

    $publication = app(UpdatePublicationDraft::class)->handle($publication, $owner, [
        'body' => '<p>The changed public version.</p>',
        'topics' => [' Family ', '<b>Travel</b>', 'family'],
    ]);

    expect($publication->privacy_reviewed_at)->toBeNull()
        ->and($publication->topics)->toBe(['Family', 'Travel']);

    expect(fn () => app(PublishPublication::class)->handle(
        publication: $publication,
        owner: $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    ))->toThrow(ValidationException::class);

    app(ConfirmPublicationPrivacyReview::class)->handle($publication->refresh(), $owner);
    app(RecordPublicationPreview::class)->handle($publication->refresh(), $owner);

    $published = app(PublishPublication::class)->handle(
        publication: $publication,
        owner: $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    );

    expect($published->isPubliclyVisible())->toBeTrue()
        ->and($published->versions()->where('reason', 'published')->firstOrFail()->settings['topics'])
        ->toBe(['Family', 'Travel']);
});
