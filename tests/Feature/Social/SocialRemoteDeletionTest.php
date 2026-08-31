<?php

use App\Actions\ArchivePublication;
use App\Actions\CopyAttachmentToPublication;
use App\Actions\DeleteUserAccount;
use App\Actions\DisconnectSocialAccount;
use App\Actions\ModeratePublicPublication;
use App\Actions\RemovePublicationMedia;
use App\Actions\StoreAttachment;
use App\Actions\UnpublishPublication;
use App\Actions\UpdatePublicationDraft;
use App\Contracts\SocialPublisherContract;
use App\Contracts\SocialPublisherRegistry;
use App\Enums\PublicationTargetStatus;
use App\Enums\RoleName;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Jobs\DeleteRemoteSocialPost;
use App\Jobs\DispatchPendingRemoteSocialPostDeletions;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use App\Services\Social\Exceptions\SanitizedSocialIntegrationException;
use App\Services\Social\RemoteSocialPostCleanup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/**
 * @return array{owner: User, publication: Publication, account: SocialAccount, target: PublicationTarget, post: SocialPost}
 */
function remoteDeletionFixture(string $remotePostId = '1891234567890123456'): array
{
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'access_token' => 'remote-deletion-secret-token',
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create([
        'status' => PublicationTargetStatus::Published,
    ]);
    $post = SocialPost::factory()->published()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
        'remote_post_id' => $remotePostId,
        'remote_url' => 'https://x.com/i/web/status/'.$remotePostId,
    ]);

    return compact('owner', 'publication', 'account', 'target', 'post');
}

function remoteDeletionPublisher(MockInterface $publisher): SocialPublisherContract
{
    if (! $publisher instanceof SocialPublisherContract) {
        throw new LogicException('The test double must implement the social publisher contract.');
    }

    return $publisher;
}

function remoteDeletionRegistry(SocialPublisherContract $publisher): SocialPublisherRegistry
{
    return new class($publisher) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };
}

test('unpublishing durably queues encrypted remote cleanup and the worker completes it', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $fixture = remoteDeletionFixture();

    app(UnpublishPublication::class)->handle($fixture['publication'], $fixture['owner']);

    $deletion = DB::table('social_post_deletions')->firstOrFail();
    expect($deletion->social_post_id)->toBe($fixture['post']->getKey())
        ->and($deletion->reason)->toBe('publication_unpublished')
        ->and($deletion->encrypted_remote_post_id)->not->toContain('1891234567890123456')
        ->and($deletion->encrypted_credentials)->not->toContain('remote-deletion-secret-token')
        ->and($deletion->completed_at)->toBeNull()
        ->and($deletion->failed_at)->toBeNull()
        ->and($fixture['post']->refresh()->status)->toBe(SocialPostStatus::DeletionPending);
    Queue::assertPushed(
        DeleteRemoteSocialPost::class,
        fn (DeleteRemoteSocialPost $job): bool => $job->socialPostDeletionId === $deletion->id,
    );

    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('delete')
        ->once()
        ->withArgs(fn (SocialAccount $account, SocialPost $post): bool => $account->access_token === 'remote-deletion-secret-token'
            && $post->remote_post_id === '1891234567890123456');

    (new DeleteRemoteSocialPost((int) $deletion->id))->handle(
        remoteDeletionRegistry(remoteDeletionPublisher($publisher)),
        app(AuditRecorder::class),
    );

    $completed = DB::table('social_post_deletions')->find($deletion->id);
    expect($completed->attempts)->toBe(1)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->encrypted_remote_post_id)->toBeNull()
        ->and($completed->encrypted_credentials)->toBeNull()
        ->and($fixture['post']->refresh()->status)->toBe(SocialPostStatus::Deleted)
        ->and($fixture['post']->remote_post_id)->toBeNull()
        ->and($fixture['post']->remote_url)->toBeNull()
        ->and(AuditEvent::query()->where('event', 'social_post.remote_deletion_completed')->exists())->toBeTrue();
});

test('disconnect snapshots cleanup credentials before clearing the social account', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $fixture = remoteDeletionFixture();

    app(DisconnectSocialAccount::class)->handle($fixture['account'], $fixture['owner']);

    $deletion = DB::table('social_post_deletions')->firstOrFail();
    $credentials = json_decode(
        Crypt::decryptString($deletion->encrypted_credentials),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($fixture['account']->refresh()->access_token)->toBe('')
        ->and($fixture['account']->revoked_at)->not->toBeNull()
        ->and($deletion->reason)->toBe('social_account_disconnected')
        ->and($credentials)->toMatchArray(['access_token' => 'remote-deletion-secret-token']);
});

test('moderation and account deletion both preserve a queued remote cleanup request', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $moderatedFixture = remoteDeletionFixture();
    PublicationTarget::factory()->publishedWebsite($moderatedFixture['publication'])->create();
    $moderator = User::factory()->create();
    $moderator->assignRole(RoleName::Moderator);

    app(ModeratePublicPublication::class)->handle(
        $moderatedFixture['publication'],
        $moderator,
        'Remove this fictional public snapshot.',
    );

    expect(DB::table('social_post_deletions')
        ->where('social_post_id', $moderatedFixture['post']->getKey())
        ->value('reason'))->toBe('publication_moderated');

    $deletingFixture = remoteDeletionFixture();
    $deletingPostId = $deletingFixture['post']->getKey();
    app(DeleteUserAccount::class)->handle($deletingFixture['owner']);

    $deletion = DB::table('social_post_deletions')
        ->where('reason', 'account_deleted')
        ->firstOrFail();
    expect(SocialPost::query()->whereKey($deletingPostId)->exists())->toBeFalse()
        ->and($deletion->social_post_id)->toBeNull()
        ->and($deletion->encrypted_credentials)->not->toBeNull()
        ->and($deletion->failed_at)->toBeNull();
});

test('remote cleanup deduplicates requests and erases copied secrets when retries exhaust', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $fixture = remoteDeletionFixture();
    $cleanup = app(RemoteSocialPostCleanup::class);
    $deletionId = $cleanup->schedule($fixture['post'], 'publication_unpublished');
    $sameDeletionId = $cleanup->schedule($fixture['post'], 'social_account_disconnected');

    expect($sameDeletionId)->toBe($deletionId)
        ->and(DB::table('social_post_deletions')->count())->toBe(1);

    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('delete')
        ->once()
        ->andThrow(new RetryableSocialPublishException('Temporary provider outage.'));
    $job = new DeleteRemoteSocialPost((int) $deletionId);

    expect(fn () => $job->handle(
        remoteDeletionRegistry(remoteDeletionPublisher($publisher)),
        app(AuditRecorder::class),
    ))->toThrow(RetryableSocialPublishException::class);

    $retrying = DB::table('social_post_deletions')->find($deletionId);
    expect($retrying->attempts)->toBe(1)
        ->and($retrying->last_error_code)->toBe('temporary_provider_failure')
        ->and($retrying->encrypted_credentials)->not->toBeNull();

    $job->failed(new RetryableSocialPublishException('Retries exhausted.'));

    $failed = DB::table('social_post_deletions')->find($deletionId);
    expect($failed->failed_at)->not->toBeNull()
        ->and($failed->last_error_code)->toBe('retries_exhausted')
        ->and($failed->encrypted_remote_post_id)->toBeNull()
        ->and($failed->encrypted_credentials)->toBeNull()
        ->and($fixture['post']->refresh()->status)->toBe(SocialPostStatus::DeletionFailed)
        ->and($fixture['post']->error_message)->toContain('provider copy may remain')
        ->and(AuditEvent::query()->where('event', 'social_post.remote_deletion_failed')->exists())->toBeTrue();
});

test('the recovery dispatcher requeues a stranded remote cleanup request', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $fixture = remoteDeletionFixture();
    $deletionId = app(RemoteSocialPostCleanup::class)->schedule(
        $fixture['post'],
        'publication_unpublished',
    );
    DB::table('social_post_deletions')->where('id', $deletionId)->update([
        'last_attempted_at' => now()->subMinutes(6),
    ]);
    Queue::fake([DeleteRemoteSocialPost::class]);

    (new DispatchPendingRemoteSocialPostDeletions)->handle();

    Queue::assertPushed(
        DeleteRemoteSocialPost::class,
        fn (DeleteRemoteSocialPost $job): bool => $job->socialPostDeletionId === $deletionId,
    );
});

test('every publication edit transition schedules remote cleanup before withdrawing visibility', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);

    $archived = remoteDeletionFixture('1891234567890123401');
    app(ArchivePublication::class)->handle($archived['publication'], $archived['owner']);

    $updated = remoteDeletionFixture('1891234567890123402');
    app(UpdatePublicationDraft::class)->handle($updated['publication'], $updated['owner'], [
        'body' => '<p>A changed public version that requires another review.</p>',
    ]);

    $removed = remoteDeletionFixture('1891234567890123403');
    $medium = PublicationMedia::factory()->for($removed['publication'])->create([
        'user_id' => $removed['owner']->getKey(),
    ]);
    app(RemovePublicationMedia::class)->handle($medium, $removed['owner']);

    expect(DB::table('social_post_deletions')->orderBy('id')->pluck('reason')->all())
        ->toBe([
            'publication_archived',
            'publication_updated',
            'publication_media_removed',
        ]);
});

test('copying safe publication media also schedules remote cleanup for a live snapshot', function (): void {
    Storage::fake('local');
    Queue::fake([DeleteRemoteSocialPost::class]);
    $fixture = remoteDeletionFixture('1891234567890123404');
    $entry = Entry::factory()->for($fixture['owner'], 'owner')->create();
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('private-source.jpg', 320, 200),
        $entry,
        $fixture['owner'],
    )->refresh();

    app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $fixture['publication'],
        owner: $fixture['owner'],
        altText: 'A safe public image.',
    );

    expect(DB::table('social_post_deletions')->value('reason'))
        ->toBe('publication_media_updated');
});

test('unexpected provider cleanup failures are reported and retried without their secret detail', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    Exceptions::fake();
    $fixture = remoteDeletionFixture();
    $deletionId = app(RemoteSocialPostCleanup::class)->schedule(
        $fixture['post'],
        'publication_unpublished',
    );
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('delete')
        ->once()
        ->andThrow(new RuntimeException('provider-token=must-never-reach-monitoring'));

    expect(fn () => (new DeleteRemoteSocialPost((int) $deletionId))->handle(
        remoteDeletionRegistry(remoteDeletionPublisher($publisher)),
        app(AuditRecorder::class),
    ))->toThrow(
        RetryableSocialPublishException::class,
        'The remote social post cleanup could not be completed.',
    );

    Exceptions::assertReported(function (SanitizedSocialIntegrationException $exception): bool {
        return $exception->operation === 'remote_post_deletion'
            && $exception->provider === 'x'
            && $exception->failureClass === 'RuntimeException'
            && ! str_contains($exception->getMessage(), 'must-never-reach-monitoring')
            && $exception->getPrevious() === null;
    });
    Exceptions::assertReportedCount(1);

    $deletion = DB::table('social_post_deletions')->find($deletionId);
    expect($deletion->last_error_code)->toBe('unknown_provider_failure')
        ->and($deletion->encrypted_credentials)->not->toBeNull()
        ->and($deletion->failed_at)->toBeNull();
});
