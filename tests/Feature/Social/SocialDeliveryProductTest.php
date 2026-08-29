<?php

use App\Actions\RetrySocialPost;
use App\Enums\PublicationTargetStatus;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Filament\App\Support\SocialAccountPresentation;
use App\Filament\App\Support\SocialDeliveryPresentation;
use App\Jobs\PublishSocialPost;
use App\Models\AuditEvent;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\User;
use App\Services\PublicationTargetConfigurator;
use App\Services\Social\Exceptions\SanitizedSocialIntegrationException;
use App\Services\SocialOnboardingReadiness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withSession;

test('publication targets require an exact account when a provider has several identities', function (): void {
    config(['memoria.social.driver' => 'fake']);

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $firstAccount = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'display_name' => 'Personal account',
    ]);
    $selectedAccount = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
        'display_name' => 'Studio account',
    ]);
    $configurator = app(PublicationTargetConfigurator::class);

    expect(fn () => $configurator->configure(
        publication: $publication,
        publishToWebsite: false,
        socialProviders: [SocialProvider::X],
        providerText: [],
        status: PublicationTargetStatus::Pending,
    ))->toThrow(ValidationException::class, 'Select the exact account');

    $targets = $configurator->configure(
        publication: $publication,
        publishToWebsite: false,
        socialProviders: [],
        providerText: [],
        status: PublicationTargetStatus::Pending,
        socialAccountIds: [$selectedAccount->getKey()],
    );

    expect($targets)->toHaveCount(1)
        ->and($targets->first()->social_account_id)->toBe($selectedAccount->getKey())
        ->and($targets->first()->target_key)->toBe('social:x:'.$selectedAccount->getKey())
        ->and($publication->targets()->where('social_account_id', $firstAccount->getKey())->exists())->toBeFalse()
        ->and(SocialAccountPresentation::label($selectedAccount))->toContain('Studio account')
        ->and(SocialAccountPresentation::label($selectedAccount))->toContain('connection '.$selectedAccount->getKey());
});

test('an exact social account selection cannot cross the owner boundary', function (): void {
    config(['memoria.social.driver' => 'fake']);

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $foreignAccount = SocialAccount::factory()->for(User::factory(), 'owner')->create([
        'provider' => SocialProvider::LinkedIn,
    ]);

    expect(fn () => app(PublicationTargetConfigurator::class)->configure(
        publication: $publication,
        publishToWebsite: false,
        socialProviders: [],
        providerText: [],
        status: PublicationTargetStatus::Pending,
        socialAccountIds: [$foreignAccount->getKey()],
    ))->toThrow(ValidationException::class, 'unavailable');
});

test('a retry reuses one delivery identity and records a metadata-only audit event', function (): void {
    Queue::fake();

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::LinkedIn,
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $account)
        ->create(['status' => PublicationTargetStatus::Failed]);
    $post = SocialPost::factory()->failed()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::LinkedIn,
    ]);
    SocialPostFailure::factory()->create([
        'social_post_id' => $post->getKey(),
        'is_retryable' => true,
    ]);
    $idempotencyKey = $post->idempotency_key;
    $requestFingerprint = $post->request_fingerprint;
    $attemptCount = $post->attempt_count;

    $retried = app(RetrySocialPost::class)->handle($post, $owner);

    expect($retried->status)->toBe(SocialPostStatus::Pending)
        ->and($retried->idempotency_key)->toBe($idempotencyKey)
        ->and($retried->request_fingerprint)->toBe($requestFingerprint)
        ->and($retried->attempt_count)->toBe($attemptCount)
        ->and($retried->error_code)->toBeNull()
        ->and($target->refresh()->status)->toBe(PublicationTargetStatus::Pending);

    $auditEvent = AuditEvent::query()
        ->where('event', 'social_post.retry_requested')
        ->where('actor_user_id', $owner->getKey())
        ->firstOrFail();

    expect($auditEvent->auditable_id)->toBe($post->getKey())
        ->and($auditEvent->metadata)->toMatchArray([
            'provider' => SocialProvider::LinkedIn->value,
            'attempt_count' => $attemptCount,
            'idempotency_key_reused' => true,
        ])
        ->and($auditEvent->metadata)->not->toHaveKeys(['content', 'remote_url', 'error_message']);

    Queue::assertPushed(
        PublishSocialPost::class,
        fn (PublishSocialPost $job): bool => $job->socialPostId === $post->getKey(),
    );

    expect(fn () => app(RetrySocialPost::class)->handle($retried, $owner))
        ->toThrow(ValidationException::class, 'Only a stopped delivery');
    expect(AuditEvent::query()->where('event', 'social_post.retry_requested')->count())->toBe(1);
    Queue::assertPushed(PublishSocialPost::class, 1);
});

test('a permanent failure and a foreign owner cannot request a resend', function (): void {
    Queue::fake();

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $account)
        ->create(['status' => PublicationTargetStatus::Failed]);
    $post = SocialPost::factory()->failed()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
    ]);
    SocialPostFailure::factory()->create([
        'social_post_id' => $post->getKey(),
        'is_retryable' => false,
    ]);

    expect(fn () => app(RetrySocialPost::class)->handle($post, $owner))
        ->toThrow(ValidationException::class, 'rejected this request permanently')
        ->and(fn () => app(RetrySocialPost::class)->handle($post, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect($post->refresh()->status)->toBe(SocialPostStatus::Failed)
        ->and(AuditEvent::query()->where('event', 'social_post.retry_requested')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('reconnecting the exact identity permits an explicit retry of its cancelled destination', function (): void {
    Queue::fake();

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::LinkedIn,
        'provider_user_id' => 'same-provider-identity',
        'revoked_at' => null,
        'token_expires_at' => now()->addHour(),
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $account)
        ->create(['status' => PublicationTargetStatus::Cancelled]);
    $post = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::LinkedIn,
        'status' => SocialPostStatus::Disconnected,
        'error_code' => 'account_disconnected',
    ]);

    $retried = app(RetrySocialPost::class)->handle($post, $owner);

    expect($retried->status)->toBe(SocialPostStatus::Pending)
        ->and($target->refresh()->status)->toBe(PublicationTargetStatus::Pending)
        ->and(AuditEvent::query()->where('event', 'social_post.retry_requested')->exists())->toBeTrue();
    Queue::assertPushed(PublishSocialPost::class, 1);
});

test('onboarding reports unsupported and unconfigured provider states without inventing credentials', function (): void {
    config([
        'memoria.social.driver' => 'real',
        'services.x' => [
            'client_id' => 'fictional-client-id',
            'client_secret' => 'fictional-client-secret',
            'redirect' => 'https://memoria.test/app/connected-accounts/x/callback',
        ],
    ]);

    $readiness = app(SocialOnboardingReadiness::class);

    expect($readiness->for(SocialProvider::X)['available'])->toBeTrue()
        ->and($readiness->for(SocialProvider::X)['message'])->toContain('OAuth consent')
        ->and($readiness->for(SocialProvider::Facebook)['available'])->toBeFalse()
        ->and($readiness->for(SocialProvider::Facebook)['message'])->toContain('Page access token')
        ->and($readiness->for(SocialProvider::Facebook)['message'])->toContain('not start a personal-profile OAuth flow')
        ->and($readiness->for(SocialProvider::Mastodon)['available'])->toBeFalse()
        ->and($readiness->for(SocialProvider::Mastodon)['message'])->toContain('instance-specific OAuth')
        ->and($readiness->for(SocialProvider::Mastodon)['message'])->toContain('not ask for an instance password');

    config(['services.x.client_secret' => null]);
    expect($readiness->for(SocialProvider::X)['available'])->toBeFalse();
});

test('unsupported onboarding stops before oauth and reconnecting a foreign account is hidden', function (): void {
    config([
        'memoria.social.driver' => 'real',
        'services.x' => [
            'client_id' => 'fictional-client-id',
            'client_secret' => 'fictional-client-secret',
            'redirect' => 'https://memoria.test/app/connected-accounts/x/callback',
        ],
    ]);

    $owner = User::factory()->create();
    $foreignAccount = SocialAccount::factory()->for(User::factory(), 'owner')->expired()->create([
        'provider' => SocialProvider::X,
    ]);

    actingAs($owner);
    get(route('social.redirect', ['provider' => SocialProvider::Facebook->value]))
        ->assertUnprocessable()
        ->assertSee('Page access token');

    get(route('social.redirect', [
        'provider' => SocialProvider::X->value,
        'reconnect' => $foreignAccount->getKey(),
    ]))
        ->assertNotFound();
});

test('an exact reconnect rejects a different provider identity without replacing credentials', function (): void {
    config([
        'memoria.social.driver' => 'real',
        'services.x' => [
            'client_id' => 'fictional-client-id',
            'client_secret' => 'fictional-client-secret',
            'redirect' => 'https://memoria.test/app/connected-accounts/x/callback',
        ],
    ]);

    $owner = User::factory()->create();
    $account = SocialAccount::factory()->for($owner, 'owner')->expired()->create([
        'provider' => SocialProvider::X,
        'provider_user_id' => 'expected-provider-user',
        'access_token' => 'existing-encrypted-token',
    ]);
    $providerUser = SocialiteUser::fake([
        'id' => 'different-provider-user',
        'nickname' => 'different-identity',
        'name' => 'Different Identity',
        'token' => 'must-not-be-stored',
    ]);
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->once()->with('x')->andReturn($provider);

    actingAs($owner);
    withSession(['memoria.social.reconnect.x' => $account->getKey()]);
    get(route('social.callback', ['provider' => SocialProvider::X->value]))
        ->assertRedirect('/app/social-accounts')
        ->assertSessionHasErrors('social_account')
        ->assertSessionHas('filament.notifications');

    expect($account->refresh()->provider_user_id)->toBe('expected-provider-user')
        ->and($account->access_token)->toBe('existing-encrypted-token')
        ->and(AuditEvent::query()->where('event', 'social_account.connected')->exists())->toBeFalse();
});

test('an unexpected oauth callback failure is reported without its provider message', function (): void {
    config([
        'memoria.social.driver' => 'real',
        'services.x' => [
            'client_id' => 'fictional-client-id',
            'client_secret' => 'fictional-client-secret',
            'redirect' => 'https://memoria.test/app/connected-accounts/x/callback',
        ],
    ]);
    Exceptions::fake();
    $owner = User::factory()->create();
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')
        ->once()
        ->andThrow(new RuntimeException('provider-token=must-never-reach-monitoring'));
    Socialite::shouldReceive('driver')->once()->with('x')->andReturn($provider);

    actingAs($owner);
    get(route('social.callback', ['provider' => SocialProvider::X->value]))
        ->assertRedirect('/app/social-accounts')
        ->assertSessionHasErrors('social_account');

    Exceptions::assertReported(function (SanitizedSocialIntegrationException $exception): bool {
        return $exception->operation === 'oauth_callback'
            && $exception->provider === 'x'
            && $exception->failureClass === 'RuntimeException'
            && ! str_contains($exception->getMessage(), 'must-never-reach-monitoring')
            && $exception->getPrevious() === null;
    });
    Exceptions::assertReportedCount(1);
});

test('delivery presentation never turns an untrusted remote url into a provider link', function (): void {
    config(['memoria.social.driver' => 'real']);

    $post = new SocialPost;
    $post->forceFill([
        'provider' => SocialProvider::X,
        'status' => SocialPostStatus::Published,
        'remote_url' => 'https://x.com/quiet-writer/status/42',
    ]);

    expect(SocialDeliveryPresentation::safeRemoteUrl($post))->toBe('https://x.com/quiet-writer/status/42')
        ->and(SocialDeliveryPresentation::label($post))->toBe('Published');

    foreach ([
        'http://x.com/quiet-writer/status/42',
        'https://x.com.evil.example/quiet-writer/status/42',
        'https://x.com:8443/quiet-writer/status/42',
        'https://user@example.com/quiet-writer/status/42',
    ] as $unsafeUrl) {
        $post->remote_url = $unsafeUrl;
        expect(SocialDeliveryPresentation::safeRemoteUrl($post))->toBeNull();
    }

    config(['memoria.social.driver' => 'fake']);
    $post->remote_url = 'https://x.com/quiet-writer/status/42';
    expect(SocialDeliveryPresentation::label($post))->toBe('Simulated success')
        ->and(SocialDeliveryPresentation::description($post))->toContain('no external post was created');

    config(['memoria.social.driver' => 'real']);
    $post->status = SocialPostStatus::DeletionPending;
    expect(SocialDeliveryPresentation::label($post))->toBe('Removal requested')
        ->and(SocialDeliveryPresentation::description($post))->toContain('may still be visible');
    $post->status = SocialPostStatus::Deleted;
    expect(SocialDeliveryPresentation::label($post))->toBe('Removed from provider')
        ->and(SocialDeliveryPresentation::description($post))->toContain('copies or caches may still exist');
    $post->status = SocialPostStatus::DeletionFailed;
    expect(SocialDeliveryPresentation::label($post))->toBe('Removal failed')
        ->and(SocialDeliveryPresentation::description($post))->toContain('external copy may remain');
});

test('account readiness rejects unsafe mastodon origins and malformed facebook page metadata', function (): void {
    $mastodonAccount = SocialAccount::factory()->make([
        'provider' => SocialProvider::Mastodon,
        'server_url' => 'https://127.0.0.1',
    ]);
    $facebookAccount = SocialAccount::factory()->make([
        'provider' => SocialProvider::Facebook,
        'metadata' => ['page_id' => '../personal-profile'],
    ]);

    expect(SocialAccountPresentation::configurationIssue($mastodonAccount))->toContain('safe HTTPS')
        ->and(SocialAccountPresentation::configurationIssue($facebookAccount))->toContain('Facebook Page');

    $mastodonAccount->server_url = 'https://social.example.net/';
    $facebookAccount->forceFill(['metadata' => ['page_id' => '123456789']]);

    expect(SocialAccountPresentation::configurationIssue($mastodonAccount))->toBeNull()
        ->and(SocialAccountPresentation::configurationIssue($facebookAccount))->toBeNull();
});

test('the target service rejects incomplete facebook and unsafe mastodon account records', function (): void {
    config(['memoria.social.driver' => 'fake']);

    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $facebookAccount = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Facebook,
        'metadata' => ['page_id' => 'personal-profile'],
    ]);
    $mastodonAccount = SocialAccount::factory()->for($owner, 'owner')->create([
        'provider' => SocialProvider::Mastodon,
        'server_url' => 'https://localhost',
    ]);
    $configurator = app(PublicationTargetConfigurator::class);

    expect(fn () => $configurator->configure(
        publication: $publication,
        publishToWebsite: false,
        socialProviders: [],
        providerText: [],
        status: PublicationTargetStatus::Pending,
        socialAccountIds: [$facebookAccount->getKey()],
    ))->toThrow(ValidationException::class, 'Facebook Page');

    expect(fn () => $configurator->configure(
        publication: $publication,
        publishToWebsite: false,
        socialProviders: [],
        providerText: [],
        status: PublicationTargetStatus::Pending,
        socialAccountIds: [$mastodonAccount->getKey()],
    ))->toThrow(ValidationException::class, 'safe HTTPS Mastodon');
});

test('the social history page is owner scoped and explains simulation truthfully', function (): void {
    config(['memoria.social.driver' => 'fake']);

    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $ownerPublication = Publication::factory()->for($owner, 'owner')->create([
        'title' => 'A visible delivery for this owner',
    ]);
    $foreignPublication = Publication::factory()->for($foreignOwner, 'owner')->create([
        'title' => 'A delivery belonging to someone else',
    ]);
    SocialPost::factory()->published()->for($ownerPublication)->create([
        'user_id' => $owner->getKey(),
        'provider' => SocialProvider::X,
        'remote_url' => 'https://x.com/quiet-writer/status/42',
    ]);
    SocialPost::factory()->published()->for($foreignPublication)->create([
        'user_id' => $foreignOwner->getKey(),
        'provider' => SocialProvider::X,
        'remote_url' => 'https://x.com/other-writer/status/24',
    ]);

    actingAs($owner);
    get('/app/social-posts')
        ->assertOk()
        ->assertSee('Social delivery history')
        ->assertSee('A visible delivery for this owner')
        ->assertSee('Simulated success')
        ->assertDontSee('A delivery belonging to someone else');
});
