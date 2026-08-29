<?php

use App\Actions\CreateEntryShare;
use App\Actions\CreateShareLink;
use App\Actions\RevokeEntryShare;
use App\Actions\RevokeShareLink;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('renders only safety-checked unlisted attachments and closes their route immediately', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Files for one trusted reader',
    ]);
    $clean = Attachment::factory()->for($entry)->for($owner, 'owner')->document()->create([
        'path' => 'private/attachments/allowed-note.pdf',
        'download_name' => 'allowed-note.pdf',
    ]);
    $pending = Attachment::factory()->for($entry)->for($owner, 'owner')->pendingScan()->create([
        'path' => 'private/attachments/pending-photo.jpg',
        'download_name' => 'pending-photo.jpg',
    ]);
    Storage::disk('local')->put($clean->path, 'clean document bytes');
    Storage::disk('local')->put($pending->path, 'unscanned image bytes');
    $created = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        expiresAt: now()->addHour(),
        includeAttachments: true,
    );
    $attachmentUrl = route('shares.attachments.show', [
        'token' => $created->token,
        'attachment' => $clean,
    ]);

    $this->get($created->url)
        ->assertOk()
        ->assertSee('allowed-note.pdf')
        ->assertDontSee('pending-photo.jpg')
        ->assertSee($attachmentUrl, false);
    $this->get($attachmentUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    app(RevokeShareLink::class)->handle($created->shareLink, $owner);

    $this->get($created->url)->assertNotFound();
    $this->get($attachmentUrl)->assertNotFound();
});

it('enforces unlisted expiry and the explicit attachment inclusion choice', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $attachment = Attachment::factory()->for($entry)->for($owner, 'owner')->create([
        'path' => 'private/attachments/expiring-photo.jpg',
        'download_name' => 'expiring-photo.jpg',
    ]);
    Storage::disk('local')->put($attachment->path, 'clean image bytes');
    $excluded = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        expiresAt: now()->addHour(),
        includeAttachments: false,
    );
    $excludedAttachmentUrl = route('shares.attachments.show', [
        'token' => $excluded->token,
        'attachment' => $attachment,
    ]);

    $this->get($excluded->url)
        ->assertOk()
        ->assertDontSee('expiring-photo.jpg');
    $this->get($excludedAttachmentUrl)->assertNotFound();

    $expiring = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        expiresAt: now()->addMinute(),
        includeAttachments: true,
    );
    $expiringAttachmentUrl = route('shares.attachments.show', [
        'token' => $expiring->token,
        'attachment' => $attachment,
    ]);
    $expiring->shareLink->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->get($expiring->url)->assertNotFound();
    $this->get($expiringAttachmentUrl)->assertNotFound();
});

it('renders registered shared files and denies downloads after expiry or revocation', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Registered shared memory',
    ]);
    $attachment = Attachment::factory()->for($entry)->for($owner, 'owner')->document()->create([
        'path' => 'private/attachments/registered-note.pdf',
        'download_name' => 'registered-note.pdf',
    ]);
    Storage::disk('local')->put($attachment->path, 'clean registered bytes');
    $share = app(CreateEntryShare::class)->handle(
        entry: $entry,
        owner: $owner,
        recipient: $recipient,
        expiresAt: now()->addHour(),
        includeAttachments: true,
    );
    $downloadUrl = route('attachments.download', $attachment);

    $this->actingAs($recipient)
        ->get(route('filament.app.pages.shared-with-me', ['memory' => $entry->getKey()]))
        ->assertOk()
        ->assertSee('registered-note.pdf')
        ->assertSee($downloadUrl, false);
    $this->actingAs($recipient)->get($downloadUrl)->assertOk();

    $share->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->actingAs($recipient)->get($downloadUrl)->assertForbidden();

    $share->forceFill(['expires_at' => null])->save();
    app(RevokeEntryShare::class)->handle($share, $owner);

    $this->actingAs($recipient)->get($downloadUrl)->assertForbidden();
    $this->actingAs($recipient)
        ->get(route('filament.app.pages.shared-with-me', ['memory' => $entry->getKey()]))
        ->assertOk()
        ->assertDontSee('registered-note.pdf');

});
