<?php

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('registered sharing is view-only, revocable, and includes only clean attachments when selected', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $stranger = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'A private memory shared intentionally',
        'body' => '<p>Private source visible only to the selected account.</p>',
        'location_name' => 'A location the owner chose to name',
        'latitude' => 35.6892,
        'longitude' => 51.389,
    ]);
    $clean = Attachment::factory()->for($entry)->for($owner, 'owner')->create([
        'path' => 'attachments/clean-shared.pdf',
        'download_name' => 'clean-shared.pdf',
    ]);
    $pending = Attachment::factory()->for($entry)->for($owner, 'owner')->pendingScan()->create([
        'path' => 'attachments/pending-shared.pdf',
        'download_name' => 'pending-shared.pdf',
    ]);
    Storage::disk('local')->put($clean->path, 'clean bytes');
    Storage::disk('local')->put($pending->path, 'pending bytes');

    $created = $this->actingAs($owner)->postJson(
        route('entry-shares.store', $entry),
        [
            'recipient_email' => $recipient->email,
            'include_attachments' => true,
            'expires_at' => now()->addDay()->toIso8601String(),
        ],
    )->assertCreated()->assertJsonPath('data.recipient_user_id', $recipient->getKey());
    $share = EntryShare::query()->findOrFail($created->json('data.id'));

    $this->actingAs($recipient)
        ->getJson(route('entries.shared.index'))
        ->assertOk()
        ->assertJsonFragment(['entry_id' => $entry->getKey()]);
    $this->actingAs($recipient)
        ->getJson(route('entries.shared.show', $entry))
        ->assertOk()
        ->assertJsonPath('data.entry.body', $entry->body)
        ->assertJsonPath('data.entry.location_name', 'A location the owner chose to name')
        ->assertJsonMissingPath('data.entry.latitude')
        ->assertJsonMissingPath('data.entry.longitude')
        ->assertJsonCount(1, 'data.attachments')
        ->assertJsonPath('data.attachments.0.id', $clean->getKey());
    $this->actingAs($recipient)
        ->putJson(route('entries.update', $entry), [
            'body' => '<p>A recipient tried to overwrite the owner.</p>',
            'revision' => $entry->revision,
        ])
        ->assertForbidden();
    expect($entry->refresh()->body)->toContain('selected account');

    $this->actingAs($recipient)
        ->get(route('attachments.download', $clean))
        ->assertOk();
    $this->actingAs($recipient)
        ->get(route('attachments.download', $pending))
        ->assertStatus(423);
    $this->actingAs($stranger)
        ->getJson(route('entries.shared.show', $entry))
        ->assertNotFound();

    $shareAudit = AuditEvent::query()
        ->where('event', 'entry_share.created')
        ->where('auditable_id', $share->getKey())
        ->firstOrFail();
    expect(json_encode($shareAudit->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('Private source');

    $this->actingAs($owner)
        ->delete(route('entry-shares.destroy', $share))
        ->assertRedirect();
    $this->actingAs($recipient)
        ->getJson(route('entries.shared.show', $entry))
        ->assertNotFound();
    expect($share->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()
            ->where('event', 'entry_share.revoked')
            ->where('auditable_id', $share->getKey())
            ->exists())->toBeTrue();
});

test('disabled accounts cannot be selected as registered share recipients', function (): void {
    $owner = User::factory()->create();
    $disabledRecipient = User::factory()->disabled()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->postJson(route('entry-shares.store', $entry), [
        'recipient_email' => $disabledRecipient->email,
    ])->assertUnprocessable()->assertJsonValidationErrors('recipient_email');

    expect(EntryShare::query()->where('shared_with_user_id', $disabledRecipient->getKey())->exists())
        ->toBeFalse();
});
