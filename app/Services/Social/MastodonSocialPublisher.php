<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;

class MastodonSocialPublisher extends ProviderSocialPublisher
{
    public function __construct(
        SocialHttpClient $http,
        private readonly MastodonHostResolver $hostResolver,
    ) {
        parent::__construct($http);
    }

    public function supports(SocialProvider $provider): bool
    {
        return $provider === SocialProvider::Mastodon;
    }

    public function supportsIdempotentPublish(): bool
    {
        return true;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        $serverUrl = $this->serverUrl($account);
        $content = $this->textOnlyContent(
            $socialPost,
            $publication,
            $this->maximumCharacters($account, $serverUrl),
        );
        $response = $this->send(
            fn () => $this->client($account, $idempotencyKey)->post("{$serverUrl}/api/v1/statuses", [
                'status' => $content,
            ]),
        );
        $remoteId = $this->requireRemoteId($response->json('id'), '/\A[0-9]+\z/');
        $remoteUrl = $response->json('url');

        if (! is_string($remoteUrl) || ! $this->isStatusUrl($serverUrl, $remoteUrl, $remoteId)) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }

        return new SocialPublishResult($remoteId, $remoteUrl);
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        $serverUrl = $this->serverUrl($account);
        $remoteId = $this->requireRemoteId($socialPost->remote_post_id, '/\A[0-9]+\z/');

        $response = $this->sendDeletion(
            fn () => $this->client($account)->delete("{$serverUrl}/api/v1/statuses/{$remoteId}"),
        );

        if ($response !== null && $response->json('id') !== $remoteId) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }
    }

    private function serverUrl(SocialAccount $account): string
    {
        if (! is_string($account->server_url) || trim($account->server_url) === '') {
            throw new PermanentSocialPublishException('The Mastodon server is not configured.');
        }

        $parts = parse_url($account->server_url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new PermanentSocialPublishException('The Mastodon server is not safe to contact.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! $this->hasSafeHostnameSyntax($host)) {
            throw new PermanentSocialPublishException('The Mastodon server is not safe to contact.');
        }

        $addresses = $this->hostResolver->resolve($host);
        if ($addresses === null) {
            throw new RetryableSocialPublishException('The social provider request could not be completed.');
        }

        if ($addresses === [] || ! $this->hasOnlyPublicAddresses($addresses)) {
            throw new PermanentSocialPublishException('The Mastodon server is not safe to contact.');
        }

        return "https://{$host}";
    }

    private function maximumCharacters(SocialAccount $account, string $serverUrl): int
    {
        $response = $this->send(
            fn () => $this->client($account)->get("{$serverUrl}/api/v2/instance"),
        );
        $maximumCharacters = $response->json('configuration.statuses.max_characters');

        if (! is_int($maximumCharacters) || $maximumCharacters < 1 || $maximumCharacters > 100000) {
            throw new PermanentSocialPublishException(
                'The Mastodon server did not provide a safe status limit.',
            );
        }

        return $maximumCharacters;
    }

    private function hasSafeHostnameSyntax(string $host): bool
    {
        return ! ($host === ''
            || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || preg_match('/(?:\A|\.)(?:localhost|local|internal|test|invalid|example)\z/i', $host) === 1
            || preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/i', $host) === 1);
    }

    /**
     * @param  array<int, string>  $addresses
     */
    private function hasOnlyPublicAddresses(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                return false;
            }
        }

        return true;
    }

    private function isStatusUrl(string $serverUrl, string $remoteUrl, string $remoteId): bool
    {
        $server = parse_url($serverUrl);
        $remote = parse_url($remoteUrl);

        return is_array($server)
            && is_array($remote)
            && strtolower((string) ($remote['scheme'] ?? '')) === 'https'
            && strtolower((string) ($remote['host'] ?? '')) === strtolower((string) ($server['host'] ?? ''))
            && ($remote['port'] ?? 443) === 443
            && ! isset($remote['user'])
            && ! isset($remote['pass'])
            && str_ends_with(rtrim((string) ($remote['path'] ?? ''), '/'), "/{$remoteId}");
    }
}
