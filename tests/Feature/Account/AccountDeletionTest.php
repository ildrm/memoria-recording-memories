<?php

use App\Actions\DeleteUserAccount;
use App\Enums\RoleName;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Jobs\DeleteRemoteSocialPost;
use App\Jobs\DeleteStoredFile;
use App\Jobs\DispatchPendingRemoteSocialPostDeletions;
use App\Jobs\DispatchPendingStoredFileDeletions;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\Reminder;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('account deletion requires the current password and explicit confirmation', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)->delete(route('account.destroy'), [
        'password' => 'wrong-password',
        'confirmation' => 'DELETE',
    ])->assertSessionHasErrors('password');

    $this->assertModelExists($owner);

    $this->actingAs($owner)->delete(route('account.destroy'), [
        'password' => 'password',
        'confirmation' => 'not-confirmed',
    ])->assertSessionHasErrors('confirmation');

    $this->assertModelExists($owner);
});

test('confirmed account deletion removes owned application data but not another account', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'body' => '<p>Fictional private deletion test text.</p>',
    ]);
    $publication = Publication::factory()->fromEntry($entry)->published()->create();
    SocialAccount::factory()->for($owner, 'owner')->create();
    Reminder::factory()->for($owner, 'owner')->create();
    Export::factory()->for($owner, 'owner')->create();
    $otherPublication = Publication::factory()->for($otherUser, 'owner')->published()->create();
    $authoredComment = Comment::factory()->for($otherPublication)->create([
        'user_id' => $owner->getKey(),
        'body' => 'A public comment removed with the account.',
    ]);
    $authoredReport = Report::factory()->for($otherPublication)->create([
        'reporter_user_id' => $owner->getKey(),
    ]);
    Password::createToken($owner);
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $owner->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Account deletion test',
        'payload' => 'test-session-payload',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'Tests\\AccountDeletionNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $owner->getKey(),
        'data' => '{}',
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($owner)->delete(route('account.destroy'), [
        'password' => 'password',
        'confirmation' => 'DELETE',
    ])->assertRedirect('/');

    $this->assertModelMissing($owner);
    $this->assertModelExists($otherUser);
    expect(Entry::withTrashed()->whereKey($entry)->exists())->toBeFalse()
        ->and(Publication::withTrashed()->whereKey($publication)->exists())->toBeFalse()
        ->and(SocialAccount::query()->where('user_id', $owner->getKey())->exists())->toBeFalse()
        ->and(Reminder::query()->where('user_id', $owner->getKey())->exists())->toBeFalse()
        ->and(Export::query()->where('user_id', $owner->getKey())->exists())->toBeFalse()
        ->and(Comment::withTrashed()->whereKey($authoredComment)->exists())->toBeFalse()
        ->and(Report::query()->whereKey($authoredReport)->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', $owner->email)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $owner->getKey())->exists())->toBeFalse()
        ->and(DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->getKey())
            ->exists())->toBeFalse();

    $audit = AuditEvent::query()->where('event', 'account.deleted')->firstOrFail();
    expect($audit->actor_user_id)->toBeNull()
        ->and(json_encode($audit->metadata))->not->toContain('Fictional private deletion test text');
});

test('account deletion persists every cleanup row with a bounded number of queued dispatchers', function (): void {
    Queue::fake([
        DeleteRemoteSocialPost::class,
        DeleteStoredFile::class,
        DispatchPendingRemoteSocialPostDeletions::class,
        DispatchPendingStoredFileDeletions::class,
    ]);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    Attachment::factory()->for($entry)->create([
        'user_id' => $owner->getKey(),
        'disk' => 'local',
        'path' => 'private/attachments/owned.jpg',
    ]);
    $publication = Publication::factory()->for($owner, 'owner')->create();
    PublicationMedia::factory()->for($publication)->create([
        'user_id' => $owner->getKey(),
        'disk' => 'local',
        'path' => 'publication-media/owned/original.jpg',
        'metadata' => [
            'width' => 1400,
            'height' => 933,
            'variants' => [
                'thumbnail' => [
                    'path' => 'publication-media/owned/thumbnail.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 20_000,
                    'width' => 320,
                    'height' => 213,
                    'metadata_stripped' => true,
                ],
            ],
        ],
    ]);
    Export::factory()->ready()->for($owner, 'owner')->create([
        'disk' => 'local',
        'path' => 'private/exports/owned.zip',
    ]);
    Journal::factory()->for($owner, 'owner')->create([
        'cover_path' => 'private/journals/owned-cover.jpg',
    ]);
    Person::factory()->for($owner, 'owner')->create([
        'avatar_path' => 'private/people/owned-avatar.jpg',
    ]);
    $socialAccount = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'access_token' => 'account-deletion-cleanup-token',
    ]);
    foreach (['1900000000000000001', '1900000000000000002'] as $remotePostId) {
        SocialPost::factory()->published()->create([
            'publication_id' => $publication->getKey(),
            'user_id' => $owner->getKey(),
            'social_account_id' => $socialAccount->getKey(),
            'provider' => SocialProvider::X,
            'status' => SocialPostStatus::Published,
            'remote_post_id' => $remotePostId,
        ]);
    }
    $owner->profile()->update([
        'avatar_path' => 'profiles/owned-avatar.jpg',
        'avatar_disk' => 'local',
        'cover_image_path' => 'profiles/owned-cover.jpg',
        'cover_image_disk' => 'local',
    ]);
    $foreignOwner = User::factory()->create();
    $foreignEntry = Entry::factory()->for($foreignOwner, 'owner')->create();
    Attachment::factory()->for($foreignEntry)->create([
        'user_id' => $foreignOwner->getKey(),
        'path' => 'private/attachments/foreign.jpg',
    ]);

    app(DeleteUserAccount::class)->handle($owner);

    $scheduledPaths = DB::table('stored_file_deletions')
        ->pluck('encrypted_path')
        ->map(fn (string $path): string => Crypt::decryptString($path));
    expect($scheduledPaths->all())->toEqualCanonicalizing([
        'private/attachments/owned.jpg',
        'publication-media/owned/original.jpg',
        'publication-media/owned/thumbnail.jpg',
        'private/exports/owned.zip',
        'private/journals/owned-cover.jpg',
        'private/people/owned-avatar.jpg',
        'profiles/owned-avatar.jpg',
        'profiles/owned-cover.jpg',
    ])->and($scheduledPaths)->not->toContain('private/attachments/foreign.jpg')
        ->and(DB::table('social_post_deletions')->count())->toBe(2)
        ->and(DB::table('social_post_deletions')->whereNull('social_post_id')->count())->toBe(2)
        ->and(DB::table('social_post_deletions')->whereNull('encrypted_credentials')->count())->toBe(0);

    Queue::assertNotPushed(DeleteStoredFile::class);
    Queue::assertNotPushed(DeleteRemoteSocialPost::class);
    Queue::assertPushed(DispatchPendingStoredFileDeletions::class, 1);
    Queue::assertPushed(DispatchPendingRemoteSocialPostDeletions::class, 1);

    $audit = AuditEvent::query()->where('event', 'account.deleted')->firstOrFail();
    expect($audit->metadata['files_scheduled_for_removal'] ?? null)->toBe(8);
});

test('the last super administrator cannot delete their own account', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $superAdministrator = User::factory()->create();
    $superAdministrator->assignRole(RoleName::SuperAdministrator);

    expect(fn () => app(DeleteUserAccount::class)->handle($superAdministrator))
        ->toThrow(LogicException::class, 'last super administrator');

    $this->assertModelExists($superAdministrator);
});

test('disabled super administrators do not satisfy the active administrator safety invariant', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $activeSuperAdministrator = User::factory()->create();
    $activeSuperAdministrator->assignRole(RoleName::SuperAdministrator);
    $disabledSuperAdministrator = User::factory()->disabled()->create();
    $disabledSuperAdministrator->assignRole(RoleName::SuperAdministrator);

    expect($activeSuperAdministrator->isLastSuperAdministrator())->toBeTrue()
        ->and($disabledSuperAdministrator->isLastSuperAdministrator())->toBeFalse();
    expect(fn () => $activeSuperAdministrator->disable())
        ->toThrow(LogicException::class, 'last super administrator');
    expect(fn () => app(DeleteUserAccount::class)->handle($activeSuperAdministrator))
        ->toThrow(LogicException::class, 'last super administrator');
});
