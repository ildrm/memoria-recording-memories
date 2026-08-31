<?php

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Actions\ConnectSocialAccount;
use App\Actions\PublishPublication;
use App\Actions\RecordPublicationPreview;
use App\Actions\UnpublishPublication;
use App\Contracts\SocialPublisherContract;
use App\Contracts\SocialPublisherRegistry;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Jobs\DeleteRemoteSocialPost;
use App\Jobs\PublishSocialPost;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use App\Services\Social\RemoteSocialPostCleanup;
use App\Services\Social\SocialAccessTokenRefresher;
use App\Services\Social\SocialPublishResult;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

use function Illuminate\Support\enum_value;

function socialPublisherContract(MockInterface $publisher): SocialPublisherContract
{
    if (! $publisher instanceof SocialPublisherContract) {
        throw new LogicException('The test double must implement the social publisher contract.');
    }

    return $publisher;
}

test('oauth credentials are encrypted at rest and hidden from serialization', function (): void {
    $owner = User::factory()->create();
    $providerUser = SocialiteUser::fake([
        'id' => 'provider-user-42',
        'nickname' => 'quiet-writer',
        'name' => 'Quiet Writer',
        'token' => 'super-secret-access-token',
        'refreshToken' => 'super-secret-refresh-token',
        'expiresIn' => 3600,
        'approvedScopes' => ['openid', 'posts.write'],
    ]);

    $account = app(ConnectSocialAccount::class)->handle(
        $owner,
        SocialProvider::LinkedIn,
        $providerUser,
    );
    $raw = DB::table('social_accounts')->where('id', $account->getKey())->first();

    expect($account->access_token)->toBe('super-secret-access-token')
        ->and($account->refresh_token)->toBe('super-secret-refresh-token')
        ->and($raw->access_token)->not->toBe('super-secret-access-token')
        ->and($raw->refresh_token)->not->toBe('super-secret-refresh-token')
        ->and($account->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('an expired X credential is refreshed through the bounded OAuth flow before delivery', function (): void {
    config()->set('services.x.client_id', 'configured-x-client');
    config()->set('services.x.client_secret', 'configured-x-secret');
    Http::preventStrayRequests();
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->expired()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'refresh_token' => 'original-refresh-token',
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
    ]);

    app()->call([(new PublishSocialPost((int) $post->getKey())), 'handle']);

    $account->refresh();
    expect($post->refresh()->status)->toBe(SocialPostStatus::Published)
        ->and($account->access_token)->toBe('rotated-access-token')
        ->and($account->refresh_token)->toBe('rotated-refresh-token')
        ->and($account->token_expires_at?->isFuture())->toBeTrue()
        ->and($account->last_refreshed_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.x.com/2/oauth2/token'
        && $request->data() === [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'original-refresh-token',
        ]
        && $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('configured-x-client:configured-x-secret'),
        ));
});

test('a waiting X refresh re-reads rotated credentials and skips a duplicate OAuth request', function (): void {
    config()->set('services.x.client_id', 'configured-x-client');
    config()->set('services.x.client_secret', 'configured-x-secret');
    Http::preventStrayRequests();
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'duplicate-access-token',
            'refresh_token' => 'duplicate-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);
    $owner = User::factory()->create();
    $staleAccount = SocialAccount::factory()->expired()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'access_token' => 'expired-access-token',
        'refresh_token' => 'spent-refresh-token',
    ]);
    $rotatedAccount = SocialAccount::query()->findOrFail($staleAccount->getKey());
    $rotatedAccount->forceFill([
        'access_token' => 'winner-access-token',
        'refresh_token' => 'winner-refresh-token',
        'token_expires_at' => now()->addHour(),
        'last_refreshed_at' => now(),
    ])->save();

    $result = app(SocialAccessTokenRefresher::class)->refreshIfExpired($staleAccount);

    expect($result->getKey())->toBe($staleAccount->getKey())
        ->and($result->access_token)->toBe('winner-access-token')
        ->and($result->refresh_token)->toBe('winner-refresh-token')
        ->and($result->token_expires_at?->isFuture())->toBeTrue();
    Http::assertNothingSent();
});

test('an expired X refresh reports account lock contention as retryable', function (): void {
    config()->set('memoria.social.lock_seconds', 45);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'must-not-be-requested',
            'expires_in' => 7200,
        ]),
    ]);
    $owner = User::factory()->create();
    $account = SocialAccount::factory()->expired()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
    ]);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')
        ->once()
        ->with("memoria:social-account:{$account->getKey()}:token-refresh", 45)
        ->andReturn($lock);

    expect(fn () => app(SocialAccessTokenRefresher::class)->refreshIfExpired($account))
        ->toThrow(
            RetryableSocialPublishException::class,
            'The social provider credential refresh is temporarily unavailable.',
        );
    Http::assertNothingSent();
});

test('the queued provider boundary records success once and retries are idempotent', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $account)
        ->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
        'content' => 'A deliberately public fictional reflection.',
    ]);
    $job = new PublishSocialPost((int) $post->getKey());

    app()->call([$job, 'handle']);
    $firstResult = $post->refresh();

    expect($firstResult->status)->toBe(SocialPostStatus::Published)
        ->and($firstResult->remote_post_id)->not->toBeNull()
        ->and($firstResult->remote_url)->toStartWith('https://social.invalid/')
        ->and($firstResult->attempt_count)->toBe(1);

    app()->call([$job, 'handle']);
    $secondResult = $post->refresh();

    expect($secondResult->remote_post_id)->toBe($firstResult->remote_post_id)
        ->and($secondResult->attempt_count)->toBe(1)
        ->and(SocialPost::query()->where('idempotency_key', $post->idempotency_key)->count())->toBe(1);
});

test('fallback social content is bounded plain text rather than raw publication HTML', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'title' => 'A public reflection',
        'excerpt' => null,
        'body' => '<p>First paragraph.</p><p>Second <strong>thought</strong>.</p>',
    ]);
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::LinkedIn,
    ]);
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    app(RecordPublicationPreview::class)->handle($publication->refresh(), $owner);

    app(PublishPublication::class)->handle(
        publication: $publication->refresh(),
        owner: $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: false,
        socialProviders: [],
        socialAccountIds: [$account->getKey()],
    );

    $post = $publication->socialPosts()->firstOrFail();

    expect($post->content)->toBe("A public reflection\n\nFirst paragraph.Second thought.")
        ->and($post->content)->not->toContain('<p>')
        ->and(mb_strlen((string) $post->content))->toBeLessThanOrEqual(3000);
});

test('cancellation immediately before provider dispatch prevents the external call', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('publish')->never();
    $publisher->shouldReceive('supportsIdempotentPublish')->once()->andReturnTrue();
    $publisher->shouldReceive('delete')->never();
    $registry = new class(socialPublisherContract($publisher), function () use ($publication, $owner): void {
        app(UnpublishPublication::class)->handle($publication, $owner);
    }) implements SocialPublisherRegistry
    {
        public function __construct(
            private readonly SocialPublisherContract $publisher,
            private readonly Closure $beforeDispatch,
        ) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            ($this->beforeDispatch)();

            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    expect(enum_value($publication->refresh()->status))->toBe('unpublished')
        ->and(enum_value($target->refresh()->status))->toBe('cancelled')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::Cancelled)
        ->and($post->attempt_count)->toBe(0)
        ->and($post->remote_post_id)->toBeNull();
});

test('cancellation during the provider call is compensated without resurrecting the post', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('supportsIdempotentPublish')->once()->andReturnTrue();
    $publisher->shouldReceive('publish')
        ->once()
        ->andReturnUsing(function () use ($publication, $owner): SocialPublishResult {
            app(UnpublishPublication::class)->handle($publication, $owner);

            return new SocialPublishResult(
                remoteId: 'remote-created-during-cancellation',
                remoteUrl: 'https://social.invalid/mastodon/remote-created-during-cancellation',
            );
        });
    $publisher->shouldReceive('delete')
        ->once()
        ->withArgs(fn (SocialAccount $deletedAccount, SocialPost $deletedPost): bool => $deletedAccount->is($account)
            && $deletedPost->remote_post_id === 'remote-created-during-cancellation');
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    expect(enum_value($publication->refresh()->status))->toBe('unpublished')
        ->and(enum_value($target->refresh()->status))->toBe('cancelled')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::Cancelled)
        ->and($post->attempt_count)->toBe(1)
        ->and($post->remote_post_id)->toBeNull()
        ->and(DB::table('social_post_deletions')->count())->toBe(0);
});

test('failed cancellation compensation queues a deduplicated durable retry with copied credentials', function (): void {
    Queue::fake([DeleteRemoteSocialPost::class]);
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
        'access_token' => 'compensation-cleanup-token',
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('supportsIdempotentPublish')->once()->andReturnTrue();
    $publisher->shouldReceive('publish')
        ->once()
        ->andReturnUsing(function () use ($publication, $owner): SocialPublishResult {
            app(UnpublishPublication::class)->handle($publication, $owner);

            return new SocialPublishResult(
                remoteId: 'remote-needing-durable-compensation',
                remoteUrl: 'https://social.invalid/mastodon/remote-needing-durable-compensation',
            );
        });
    $publisher->shouldReceive('delete')
        ->once()
        ->andThrow(new RetryableSocialPublishException('Sensitive provider response.'));
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    $deletion = DB::table('social_post_deletions')->firstOrFail();
    $credentials = json_decode(
        Crypt::decryptString($deletion->encrypted_credentials),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($deletion->reason)->toBe('cancelled_dispatch_compensation_failed')
        ->and(Crypt::decryptString($deletion->encrypted_remote_post_id))->toBe('remote-needing-durable-compensation')
        ->and($credentials['access_token'] ?? null)->toBe('compensation-cleanup-token')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::DeletionPending)
        ->and($post->remote_post_id)->toBe('remote-needing-durable-compensation')
        ->and($post->failures()->latest('id')->first()?->error_code)->toBe('cancelled_remote_cleanup_failed');
    Queue::assertPushed(DeleteRemoteSocialPost::class, 1);

    $duplicateId = app(RemoteSocialPostCleanup::class)->schedule(
        $post->refresh(),
        'cancelled_dispatch_compensation_failed',
        $account,
    );

    expect($duplicateId)->toBe((int) $deletion->id)
        ->and(DB::table('social_post_deletions')->count())->toBe(1);

    $cleanupPublisher = Mockery::mock(SocialPublisherContract::class);
    $cleanupPublisher->shouldReceive('delete')
        ->once()
        ->andThrow(new RetryableSocialPublishException('Temporary cleanup outage.'));
    $cleanupRegistry = new class(socialPublisherContract($cleanupPublisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    expect(fn () => (new DeleteRemoteSocialPost((int) $deletion->id))->handle(
        $cleanupRegistry,
        app(AuditRecorder::class),
    ))->toThrow(RetryableSocialPublishException::class);

    $retryingDeletion = DB::table('social_post_deletions')->find($deletion->id);
    expect($retryingDeletion->attempts)->toBe(1)
        ->and($retryingDeletion->last_error_code)->toBe('temporary_provider_failure')
        ->and($retryingDeletion->encrypted_credentials)->not->toBeNull();
});

test('an uncertain non-idempotent provider outcome requires an explicit retry', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->andThrow(new RetryableSocialPublishException(
            'The social provider request could not be completed.',
            outcomeIsUncertain: true,
        ));
    $publisher->shouldReceive('supportsIdempotentPublish')->twice()->andReturnFalse();
    $publisher->shouldReceive('delete')->never();
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    expect(enum_value($target->refresh()->status))->toBe('failed')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::Failed)
        ->and($post->attempt_count)->toBe(1)
        ->and($post->error_code)->toBe('delivery_outcome_unknown')
        ->and($post->remote_post_id)->toBeNull()
        ->and($post->failures()->latest('id')->first()?->is_retryable)->toBeTrue();
});

test('an uncertain Mastodon outcome remains safely retryable with its idempotency key', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('supportsIdempotentPublish')->twice()->andReturnTrue();
    $publisher->shouldReceive('publish')
        ->once()
        ->andThrow(new RetryableSocialPublishException(
            'The social provider request could not be completed.',
            outcomeIsUncertain: true,
        ));
    $publisher->shouldReceive('delete')->never();
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    expect(fn () => (new PublishSocialPost((int) $post->getKey()))
        ->handle($registry, app(AuditRecorder::class)))
        ->toThrow(RetryableSocialPublishException::class);

    expect($post->refresh()->status)->toBe(SocialPostStatus::Retrying)
        ->and($post->attempt_count)->toBe(1)
        ->and($post->idempotency_key)->not->toBeEmpty()
        ->and($post->remote_post_id)->toBeNull();
});

test('provider authorization rejection becomes a token-expired delivery state', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('supportsIdempotentPublish')->once()->andReturnFalse();
    $publisher->shouldReceive('publish')
        ->once()
        ->andThrow(new PermanentSocialPublishException(
            'The social provider authorization has expired.',
            errorCode: 'token_expired',
        ));
    $publisher->shouldReceive('delete')->never();
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    expect(enum_value($target->refresh()->status))->toBe('failed')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::TokenExpired)
        ->and($post->error_code)->toBe('token_expired')
        ->and($post->remote_post_id)->toBeNull()
        ->and($post->failures()->latest('id')->first()?->error_code)->toBe('token_expired');
});

test('a stranded processing post is not automatically resent without provider idempotency', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::LinkedIn,
    ]);
    $target = PublicationTarget::factory()->forSocialAccount($publication, $account)->create([
        'status' => 'processing',
    ]);
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::LinkedIn,
        'status' => SocialPostStatus::Processing,
        'attempt_count' => 1,
        'last_attempted_at' => now()->subMinute(),
    ]);
    $publisher = Mockery::mock(SocialPublisherContract::class);
    $publisher->shouldReceive('publish')->never();
    $publisher->shouldReceive('supportsIdempotentPublish')->once()->andReturnFalse();
    $publisher->shouldReceive('delete')->never();
    $registry = new class(socialPublisherContract($publisher)) implements SocialPublisherRegistry
    {
        public function __construct(private readonly SocialPublisherContract $publisher) {}

        public function for(SocialProvider $provider): SocialPublisherContract
        {
            return $this->publisher;
        }
    };

    (new PublishSocialPost((int) $post->getKey()))->handle($registry, app(AuditRecorder::class));

    expect(enum_value($target->refresh()->status))->toBe('failed')
        ->and($post->refresh()->status)->toBe(SocialPostStatus::Failed)
        ->and($post->attempt_count)->toBe(1)
        ->and($post->error_code)->toBe('delivery_outcome_unknown')
        ->and($post->failures)->toHaveCount(1);
});

test('a published snapshot cannot be published twice through the domain action', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();

    expect(fn () => app(PublishPublication::class)->handle(
        publication: $publication,
        owner: $owner,
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    ))->toThrow(ValidationException::class);

    expect($publication->versions()->count())->toBe(0)
        ->and($publication->targets()->count())->toBe(0)
        ->and($publication->socialPosts()->count())->toBe(0);
});

test('the social job terminally rejects cross-account ownership chains before a provider call', function (): void {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $foreignAccount = SocialAccount::factory()->for($foreignOwner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $foreignAccount)
        ->create();
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $foreignAccount->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);

    app()->call([(new PublishSocialPost((int) $post->getKey())), 'handle']);

    expect($post->refresh()->status)->toBe(SocialPostStatus::Failed)
        ->and($post->error_code)->toBe('ownership_mismatch')
        ->and($post->attempt_count)->toBe(0)
        ->and($post->remote_post_id)->toBeNull();

    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
    ]);
    $foreignPublication = Publication::factory()->for($foreignOwner, 'owner')->published()->create();
    $foreignTarget = PublicationTarget::factory()
        ->forSocialAccount($foreignPublication, $account)
        ->create(['user_id' => $owner->getKey()]);
    $publicationMismatch = SocialPost::factory()->create([
        'publication_id' => $foreignPublication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $foreignTarget->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::Mastodon,
    ]);

    app()->call([(new PublishSocialPost((int) $publicationMismatch->getKey())), 'handle']);

    expect($publicationMismatch->refresh()->status)->toBe(SocialPostStatus::Failed)
        ->and($publicationMismatch->error_code)->toBe('ownership_mismatch')
        ->and($publicationMismatch->attempt_count)->toBe(0)
        ->and($publicationMismatch->remote_post_id)->toBeNull();
});
