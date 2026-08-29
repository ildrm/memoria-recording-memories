<?php

use App\Contracts\SocialPublisherContract;
use App\Contracts\SocialPublisherRegistry;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use App\Services\Social\FacebookPageSocialPublisher;
use App\Services\Social\FakeSocialPublisher;
use App\Services\Social\LinkedInSocialPublisher;
use App\Services\Social\MastodonHostResolver;
use App\Services\Social\MastodonSocialPublisher;
use App\Services\Social\SocialPublishResult;
use App\Services\Social\UnavailableSocialPublisher;
use App\Services\Social\XSocialPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** @param array<string, mixed> $attributes */
function socialPublisherAccount(SocialProvider $provider, array $attributes = []): SocialAccount
{
    return (new SocialAccount)->forceFill(array_merge([
        'provider' => $provider,
        'provider_user_id' => 'member_123',
        'access_token' => 'provider-access-token',
        'metadata' => [],
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function socialPublisherPost(array $attributes = []): SocialPost
{
    return (new SocialPost)->forceFill(array_merge([
        'content' => 'A small memory worth sharing.',
        'remote_post_id' => null,
    ], $attributes));
}

function publishWith(
    SocialPublisherContract $publisher,
    SocialAccount $account,
    ?SocialPost $post = null,
): SocialPublishResult {
    return $publisher->publish(
        $account,
        $post ?? socialPublisherPost(),
        new Publication,
        hash('sha256', 'stable-social-post-key'),
    );
}

function allowPublicMastodonDns(): void
{
    $resolver = Mockery::mock(MastodonHostResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(['8.8.8.8']);
    app()->instance(MastodonHostResolver::class, $resolver);
}

test('X publishes text with user authentication and parses the post identifier', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => ['id' => '1891234567890123456', 'text' => 'A small memory worth sharing.'],
        ], 201),
    ]);

    $result = publishWith(app(XSocialPublisher::class), socialPublisherAccount(SocialProvider::X));

    expect($result->remoteId)->toBe('1891234567890123456')
        ->and($result->remoteUrl)->toBe('https://x.com/i/web/status/1891234567890123456');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.x.com/2/tweets'
        && $request->data() === ['text' => 'A small memory worth sharing.']
        && $request->hasHeader('Authorization', 'Bearer provider-access-token')
        && ! $request->hasHeader('Idempotency-Key'));
});

test('LinkedIn publishes a member post with the required versioned headers', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response('', 201, [
            'x-restli-id' => 'urn:li:share:6844785523593134080',
        ]),
    ]);

    $result = publishWith(
        app(LinkedInSocialPublisher::class),
        socialPublisherAccount(SocialProvider::LinkedIn, ['provider_user_id' => 'member_123']),
    );

    expect($result->remoteId)->toBe('urn:li:share:6844785523593134080')
        ->and($result->remoteUrl)->toBe('https://www.linkedin.com/feed/update/urn:li:share:6844785523593134080');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.linkedin.com/rest/posts'
        && $request->data() === [
            'author' => 'urn:li:person:member_123',
            'commentary' => 'A small memory worth sharing.',
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ]
        && $request->hasHeader('Authorization', 'Bearer provider-access-token')
        && $request->hasHeader('LinkedIn-Version', '202606')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && ! $request->hasHeader('Idempotency-Key'));
});

test('Facebook publishes text to an explicit Page with its Page access token', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/123456/feed' => Http::response([
            'id' => '123456_987654',
        ]),
    ]);
    $account = socialPublisherAccount(SocialProvider::Facebook, [
        'access_token' => 'facebook-page-access-token',
        'metadata' => ['page_id' => '123456'],
    ]);

    $result = publishWith(app(FacebookPageSocialPublisher::class), $account);

    expect($result->remoteId)->toBe('123456_987654')
        ->and($result->remoteUrl)->toBe('https://www.facebook.com/123456/posts/987654');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v25.0/123456/feed'
        && $request->data() === ['message' => 'A small memory worth sharing.']
        && $request->hasHeader('Authorization', 'Bearer facebook-page-access-token')
        && ! $request->hasHeader('Idempotency-Key'));
});

test('Mastodon publishes text to the configured instance with duplicate protection', function (): void {
    allowPublicMastodonDns();
    Http::fake([
        'https://mastodon.social/api/v2/instance' => Http::response([
            'configuration' => ['statuses' => ['max_characters' => 500]],
        ]),
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '103254962155278888',
            'url' => 'https://mastodon.social/@quietwriter/103254962155278888',
        ]),
    ]);
    $idempotencyKey = hash('sha256', 'stable-social-post-key');
    $account = socialPublisherAccount(SocialProvider::Mastodon, [
        'server_url' => 'https://mastodon.social/',
    ]);

    $result = publishWith(app(MastodonSocialPublisher::class), $account);

    expect($result->remoteId)->toBe('103254962155278888')
        ->and($result->remoteUrl)->toBe('https://mastodon.social/@quietwriter/103254962155278888');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://mastodon.social/api/v1/statuses'
        && $request->data() === ['status' => 'A small memory worth sharing.']
        && $request->hasHeader('Authorization', 'Bearer provider-access-token')
        && $request->hasHeader('Idempotency-Key', $idempotencyKey));
});

test('providers reject content beyond their conservative text limits before dispatch', function (
    string $publisherClass,
    SocialProvider $provider,
    int $maximumCharacters,
    array $accountAttributes,
): void {
    $post = socialPublisherPost(['content' => str_repeat('a', $maximumCharacters + 1)]);

    expect(fn () => publishWith(
        app($publisherClass),
        socialPublisherAccount($provider, $accountAttributes),
        $post,
    ))->toThrow(
        PermanentSocialPublishException::class,
        'The social publication content exceeds this provider’s supported limit.',
    );
    Http::assertNothingSent();
})->with([
    'X weighted-safe bound' => [XSocialPublisher::class, SocialProvider::X, 140, []],
    'LinkedIn' => [LinkedInSocialPublisher::class, SocialProvider::LinkedIn, 3000, []],
    'Facebook product bound' => [
        FacebookPageSocialPublisher::class,
        SocialProvider::Facebook,
        5000,
        ['metadata' => ['page_id' => '123456']],
    ],
]);

test('Mastodon enforces the connected instance status limit', function (): void {
    allowPublicMastodonDns();
    Http::fake([
        'https://mastodon.social/api/v2/instance' => Http::response([
            'configuration' => ['statuses' => ['max_characters' => 12]],
        ]),
    ]);
    $account = socialPublisherAccount(SocialProvider::Mastodon, [
        'server_url' => 'https://mastodon.social',
    ]);

    expect(fn () => publishWith(
        app(MastodonSocialPublisher::class),
        $account,
        socialPublisherPost(['content' => 'thirteen chars']),
    ))->toThrow(
        PermanentSocialPublishException::class,
        'The social publication content exceeds this provider’s supported limit.',
    );
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://mastodon.social/api/v2/instance');
});

test('text-only adapters reject publication media instead of silently dropping it', function (): void {
    $publication = Publication::factory()->create();
    PublicationMedia::factory()->for($publication)->create();

    expect(fn () => app(XSocialPublisher::class)->publish(
        socialPublisherAccount(SocialProvider::X),
        socialPublisherPost(),
        $publication,
        hash('sha256', 'stable-social-post-key'),
    ))->toThrow(
        PermanentSocialPublishException::class,
        'This social provider adapter supports text-only publications.',
    );
    Http::assertNothingSent();
});

test('each provider deletes its own remote text post', function (
    string $publisherClass,
    SocialProvider $provider,
    string $remoteId,
    string $endpoint,
    array $accountAttributes,
    int $status,
): void {
    if ($provider === SocialProvider::Mastodon) {
        allowPublicMastodonDns();
    }

    $responseBody = match ($provider) {
        SocialProvider::X => ['data' => ['deleted' => true]],
        SocialProvider::Facebook => ['success' => true],
        SocialProvider::Mastodon => ['id' => $remoteId],
        default => '',
    };
    Http::fake([$endpoint => Http::response($responseBody, $status)]);
    $account = socialPublisherAccount($provider, $accountAttributes);

    app($publisherClass)->delete($account, socialPublisherPost(['remote_post_id' => $remoteId]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === $endpoint
        && $request->hasHeader('Authorization', 'Bearer provider-access-token')
        && ! $request->hasHeader('Idempotency-Key'));
})->with([
    'X' => [
        XSocialPublisher::class,
        SocialProvider::X,
        '1891234567890123456',
        'https://api.x.com/2/tweets/1891234567890123456',
        [],
        200,
    ],
    'LinkedIn' => [
        LinkedInSocialPublisher::class,
        SocialProvider::LinkedIn,
        'urn:li:share:6844785523593134080',
        'https://api.linkedin.com/rest/posts/urn%3Ali%3Ashare%3A6844785523593134080',
        [],
        204,
    ],
    'Facebook' => [
        FacebookPageSocialPublisher::class,
        SocialProvider::Facebook,
        '123456_987654',
        'https://graph.facebook.com/v25.0/123456_987654',
        ['metadata' => ['page_id' => '123456']],
        200,
    ],
    'Mastodon' => [
        MastodonSocialPublisher::class,
        SocialProvider::Mastodon,
        '103254962155278888',
        'https://mastodon.social/api/v1/statuses/103254962155278888',
        ['server_url' => 'https://mastodon.social'],
        200,
    ],
]);

test('a repeated provider delete treats a missing remote post as already removed', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets/1891234567890123456' => Http::response([
            'detail' => 'provider content that must not escape',
        ], 404),
    ]);

    app(XSocialPublisher::class)->delete(
        socialPublisherAccount(SocialProvider::X),
        socialPublisherPost(['remote_post_id' => '1891234567890123456']),
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('transient HTTP statuses are retryable without exposing provider content', function (int $status): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'error' => 'provider-secret-response',
            'token' => 'provider-access-token',
        ], $status),
    ]);

    expect(fn () => publishWith(app(XSocialPublisher::class), socialPublisherAccount(SocialProvider::X)))
        ->toThrow(RetryableSocialPublishException::class, 'The social provider is temporarily unavailable.');
})->with([408, 425, 429, 500, 503]);

test('network failures are retryable without exposing connection details', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::failedConnection(),
    ]);

    try {
        publishWith(app(XSocialPublisher::class), socialPublisherAccount(SocialProvider::X));
        throw new RuntimeException('The provider request should have failed.');
    } catch (RetryableSocialPublishException $exception) {
        expect($exception->getMessage())->toBe('The social provider request could not be completed.')
            ->and($exception->outcomeIsUncertain)->toBeTrue();
    }
});

test('other client failures are permanent without exposing provider content', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'error' => 'provider-secret-response',
            'token' => 'provider-access-token',
        ], 422),
    ]);

    expect(fn () => publishWith(app(XSocialPublisher::class), socialPublisherAccount(SocialProvider::X)))
        ->toThrow(PermanentSocialPublishException::class, 'The social provider rejected the request.');
});

test('authorization failures are normalized without exposing provider content', function (
    int $status,
    string $errorCode,
): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'error' => 'provider-secret-response',
            'token' => 'provider-access-token',
        ], $status),
    ]);

    try {
        publishWith(app(XSocialPublisher::class), socialPublisherAccount(SocialProvider::X));
        throw new RuntimeException('The provider request should have failed.');
    } catch (PermanentSocialPublishException $exception) {
        expect($exception->errorCode)->toBe($errorCode)
            ->and($exception->getMessage())->not->toContain('provider-secret-response')
            ->and($exception->getMessage())->not->toContain('provider-access-token');
    }
})->with([
    'expired or invalid authorization' => [401, 'token_expired'],
    'missing provider permission' => [403, 'permission_denied'],
]);

test('Facebook fails closed without explicit Page metadata', function (): void {
    $account = socialPublisherAccount(SocialProvider::Facebook, ['metadata' => []]);

    expect(fn () => publishWith(app(FacebookPageSocialPublisher::class), $account))
        ->toThrow(PermanentSocialPublishException::class, 'The Facebook Page publishing account is not configured.');
    Http::assertNothingSent();
});

test('Facebook fails closed without a Page access token', function (): void {
    $account = socialPublisherAccount(SocialProvider::Facebook, [
        'access_token' => '',
        'metadata' => ['page_id' => '123456'],
    ]);

    expect(fn () => publishWith(app(FacebookPageSocialPublisher::class), $account))
        ->toThrow(PermanentSocialPublishException::class, 'The social account credentials are incomplete.');
    Http::assertNothingSent();
});

test('LinkedIn fails closed without a valid member author', function (): void {
    $account = socialPublisherAccount(SocialProvider::LinkedIn, [
        'provider_user_id' => 'urn:li:organization:123',
    ]);

    expect(fn () => publishWith(app(LinkedInSocialPublisher::class), $account))
        ->toThrow(PermanentSocialPublishException::class, 'The LinkedIn author is not configured.');
    Http::assertNothingSent();
});

test('Mastodon rejects unsafe server origins before making a request', function (string $serverUrl): void {
    $account = socialPublisherAccount(SocialProvider::Mastodon, ['server_url' => $serverUrl]);

    expect(fn () => publishWith(app(MastodonSocialPublisher::class), $account))
        ->toThrow(PermanentSocialPublishException::class, 'The Mastodon server is not safe to contact.');
    Http::assertNothingSent();
})->with([
    'plain HTTP' => 'http://mastodon.social',
    'loopback IPv4' => 'https://127.0.0.1',
    'loopback IPv6' => 'https://[::1]',
    'cloud metadata address' => 'https://169.254.169.254',
    'credentials in authority' => 'https://mastodon.social@127.0.0.1',
    'non-origin path' => 'https://mastodon.social/api',
    'internal host suffix' => 'https://social.internal',
]);

test('Mastodon rejects a response URL from a different origin', function (): void {
    allowPublicMastodonDns();
    Http::fake([
        'https://mastodon.social/api/v2/instance' => Http::response([
            'configuration' => ['statuses' => ['max_characters' => 500]],
        ]),
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '103254962155278888',
            'url' => 'https://attacker.example/status/103254962155278888',
        ]),
    ]);
    $account = socialPublisherAccount(SocialProvider::Mastodon, [
        'server_url' => 'https://mastodon.social',
    ]);

    expect(fn () => publishWith(app(MastodonSocialPublisher::class), $account))
        ->toThrow(PermanentSocialPublishException::class, 'The social provider returned an invalid result.');
});

test('testing always uses the fake registry even when the real driver is selected', function (): void {
    config()->set('memoria.social.driver', 'real');
    app()->forgetInstance(SocialPublisherRegistry::class);

    $publisher = app(SocialPublisherRegistry::class)->for(SocialProvider::X);

    expect($publisher)->toBeInstanceOf(FakeSocialPublisher::class);
});

test('production registers every real adapter and otherwise fails unavailable', function (): void {
    $application = app();
    $previousDriver = config('memoria.social.driver');
    $application->detectEnvironment(fn (): string => 'production');

    try {
        config()->set('memoria.social.driver', 'real');
        $application->forgetInstance(SocialPublisherRegistry::class);
        $registry = $application->make(SocialPublisherRegistry::class);

        expect($registry->for(SocialProvider::X))->toBeInstanceOf(XSocialPublisher::class)
            ->and($registry->for(SocialProvider::LinkedIn))->toBeInstanceOf(LinkedInSocialPublisher::class)
            ->and($registry->for(SocialProvider::Facebook))->toBeInstanceOf(FacebookPageSocialPublisher::class)
            ->and($registry->for(SocialProvider::Mastodon))->toBeInstanceOf(MastodonSocialPublisher::class);

        config()->set('memoria.social.driver');
        $application->forgetInstance(SocialPublisherRegistry::class);

        expect($application->make(SocialPublisherRegistry::class)->for(SocialProvider::X))
            ->toBeInstanceOf(UnavailableSocialPublisher::class);

        config()->set('memoria.social.driver', 'fake');
        $application->forgetInstance(SocialPublisherRegistry::class);

        expect($application->make(SocialPublisherRegistry::class)->for(SocialProvider::X))
            ->toBeInstanceOf(UnavailableSocialPublisher::class);
    } finally {
        config()->set('memoria.social.driver', $previousDriver);
        $application->detectEnvironment(fn (): string => 'testing');
        $application->forgetInstance(SocialPublisherRegistry::class);
    }
});
