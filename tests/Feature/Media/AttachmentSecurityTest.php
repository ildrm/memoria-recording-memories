<?php

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Actions\CopyAttachmentToPublication;
use App\Actions\DeleteAttachment;
use App\Actions\RecordPublicationPreview;
use App\Actions\RemovePublicationMedia;
use App\Actions\StoreAttachment;
use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('validated uploads use private randomized storage and server derived ownership', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    $response = $this->actingAs($owner)->post(route('attachments.store', $entry), [
        'user_id' => $attacker->getKey(),
        'file' => UploadedFile::fake()->image('family-memory.jpg', 900, 600),
    ]);

    $response->assertCreated()->assertJsonPath('data.scan_status', 'pending');
    $attachment = Attachment::query()->findOrFail($response->json('data.id'));

    expect($attachment->user_id)->toBe($owner->getKey())
        ->and($attachment->entry_id)->toBe($entry->getKey())
        ->and($attachment->path)->toStartWith("attachments/{$owner->getKey()}/{$entry->getKey()}/")
        ->and($attachment->path)->not->toContain('family-memory')
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($attachment->scanned_at)->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);

    $this->actingAs($attacker)->post(route('attachments.store', $entry), [
        'file' => UploadedFile::fake()->image('stolen.jpg'),
    ])->assertForbidden();

    $this->actingAs($owner)->post(route('attachments.store', $entry), [
        'file' => UploadedFile::fake()->create('payload.php', 2, 'application/x-php'),
    ])->assertSessionHasErrors('file');
});

test('private downloads require authorization and a clean scan result', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $stranger = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $attachment = Attachment::factory()->for($entry)->for($owner, 'owner')->pendingScan()->create([
        'path' => "attachments/{$owner->getKey()}/{$entry->getKey()}/safe.pdf",
        'download_name' => "notes\r\nInjected: value.pdf",
    ]);
    Storage::disk('local')->put($attachment->path, 'safe fictional PDF bytes');

    $this->actingAs($owner)
        ->get(route('attachments.download', $attachment))
        ->assertStatus(423);

    $attachment->forceFill([
        'scan_status' => AttachmentScanStatus::Clean,
        'scanned_at' => now(),
    ])->save();

    $download = $this->actingAs($owner)
        ->get(route('attachments.download', $attachment))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->not->toContain("\r", "\n");

    $this->actingAs($stranger)
        ->get(route('attachments.download', $attachment))
        ->assertForbidden();

    EntryShare::factory()->for($entry)->for($recipient, 'recipient')->create([
        'shared_by_user_id' => $owner->getKey(),
        'include_attachments' => true,
    ]);
    $this->actingAs($recipient)
        ->get(route('attachments.download', $attachment))
        ->assertOk();

    $attachment->forceFill(['scan_status' => AttachmentScanStatus::Rejected])->save();
    $this->actingAs($owner)
        ->get(route('attachments.download', $attachment))
        ->assertStatus(423);
});

test('clean private images become independent sanitized public copies', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('private-family-location.jpg', 320, 200),
        $entry,
        $owner,
    )->refresh();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    app(RecordPublicationPreview::class)->handle($publication->refresh(), $owner);

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Clean);
    $privateBytes = Storage::disk('local')->get($attachment->path);

    $medium = app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $publication,
        owner: $owner,
        altText: 'A privacy-safe family photograph',
        featured: true,
    );

    expect($medium->disk)->toBe('local')
        ->and($medium->path)->not->toBe($attachment->path)
        ->and($medium->path)->not->toContain('private-family-location')
        ->and($medium->original_name)->toStartWith('image.')
        ->and($medium->metadata_stripped)->toBeTrue()
        ->and($medium->source_attachment_id)->toBe($attachment->getKey())
        ->and($medium->publication()->firstOrFail()->privacy_reviewed_at)->toBeNull()
        ->and(hash('sha256', Storage::disk($medium->disk)->get($medium->path)))
        ->not->toBe(hash('sha256', $privateBytes));
    Storage::disk('local')->assertExists($attachment->path);
    Storage::disk('local')->assertExists($medium->path);
    Storage::disk('public')->assertMissing($medium->path);
    $this->get('/storage/'.$medium->path)->assertForbidden();

    $this->get(route('publications.media.show', $medium))->assertNotFound();
    $previewResponse = $this->actingAs($owner)
        ->get(route('publications.media.preview', $medium))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($previewResponse->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store')
        ->toContain('max-age=0');

    $attachment->forceFill([
        'scan_status' => AttachmentScanStatus::Pending,
        'scanned_at' => null,
    ])->save();
    expect(fn () => app(CopyAttachmentToPublication::class)->handle(
        $attachment,
        $publication,
        $owner,
    ))->toThrow(ValidationException::class);

    $attachment->forceFill([
        'scan_status' => AttachmentScanStatus::Rejected,
        'scanned_at' => now(),
    ])->save();
    expect(fn () => app(CopyAttachmentToPublication::class)->handle(
        $attachment,
        $publication,
        $owner,
    ))->toThrow(ValidationException::class);

    app(RemovePublicationMedia::class)->handle($medium, $owner);

    expect(PublicationMedia::query()->whereKey($medium->getKey())->exists())->toBeFalse();
    Storage::disk('local')->assertExists($attachment->path);
    Storage::disk('local')->assertMissing($medium->path);
    expect(DB::table('stored_file_deletions')
        ->where('reason', 'publication_media_removed')
        ->whereNotNull('completed_at')
        ->exists())->toBeTrue();
});

test('the deterministic scanner rejects its malware fixture without auditing file contents', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $marker = 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE';

    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->createWithContent('scanner-fixture.txt', "prefix {$marker} suffix"),
        $entry,
        $owner,
    )->refresh();

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Rejected)
        ->and($attachment->scanned_at)->not->toBeNull();

    $audit = AuditEvent::query()
        ->where('event', 'attachment.scan_completed')
        ->where('auditable_id', $attachment->getKey())
        ->latest('id')
        ->firstOrFail();
    $serializedMetadata = json_encode($audit->metadata, JSON_THROW_ON_ERROR);

    expect($audit->metadata)->toMatchArray([
        'status' => AttachmentScanStatus::Rejected->value,
        'scanner' => 'fake',
        'reason_code' => 'malware_detected',
    ])->and($serializedMetadata)
        ->not->toContain($marker)
        ->not->toContain($attachment->path);
});

test('an unavailable configured scanner fails closed instead of approving an attachment', function (): void {
    Storage::fake('local');
    config()->set('memoria.attachments.scanner.driver', 'unavailable');
    app()->forgetInstance(AttachmentScanner::class);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('unscanned.jpg'),
        $entry,
        $owner,
    )->refresh();

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($attachment->scanned_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('attachments.download', $attachment))
        ->assertStatus(423);
});

test('deleting a private attachment preserves independent public derivatives and rejects legacy aliases', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $attachment = Attachment::factory()->for($entry)->for($owner, 'owner')->create([
        'path' => "attachments/{$owner->getKey()}/{$entry->getKey()}/private.jpg",
    ]);
    Storage::disk('local')->put($attachment->path, 'private bytes');
    $derivative = PublicationMedia::factory()->for($publication)->for($owner, 'owner')->create([
        'source_attachment_id' => $attachment->getKey(),
        'disk' => 'local',
        'path' => 'publication-media/independent.jpg',
    ]);
    Storage::disk('local')->put($derivative->path, 'independent sanitized bytes');

    expect(fn () => app(DeleteAttachment::class)->handle($attachment, $attacker))
        ->toThrow(AuthorizationException::class);
    expect(Attachment::query()->whereKey($attachment->getKey())->exists())->toBeTrue();

    app(DeleteAttachment::class)->handle($attachment, $owner);

    expect(Attachment::withTrashed()->whereKey($attachment->getKey())->exists())->toBeFalse()
        ->and($derivative->refresh()->source_attachment_id)->toBeNull()
        ->and(DB::table('stored_file_deletions')
            ->where('reason', 'private_attachment_deleted')
            ->whereNotNull('completed_at')
            ->exists())->toBeTrue();
    Storage::disk('local')->assertMissing($attachment->path);
    Storage::disk('local')->assertExists($derivative->path);

    $legacyAttachment = Attachment::factory()->for($entry)->for($owner, 'owner')->create([
        'disk' => 'local',
        'path' => 'attachments/legacy-shared-path.jpg',
    ]);
    PublicationMedia::factory()->for($publication)->for($owner, 'owner')->create([
        'source_attachment_id' => $legacyAttachment->getKey(),
        'disk' => 'local',
        'path' => $legacyAttachment->path,
    ]);

    expect(fn () => app(DeleteAttachment::class)->handle($legacyAttachment, $owner))
        ->toThrow(ValidationException::class);
    expect(Attachment::query()->whereKey($legacyAttachment->getKey())->exists())->toBeTrue();
});
