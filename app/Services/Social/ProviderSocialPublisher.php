<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherContract;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

abstract class ProviderSocialPublisher implements SocialPublisherContract
{
    public function __construct(protected readonly SocialHttpClient $http) {}

    public function supportsIdempotentPublish(): bool
    {
        return false;
    }

    protected function client(SocialAccount $account, ?string $idempotencyKey = null): PendingRequest
    {
        if (! is_string($account->access_token) || trim($account->access_token) === '') {
            throw new PermanentSocialPublishException('The social account credentials are incomplete.');
        }

        return $this->http->for($account, $idempotencyKey);
    }

    /**
     * @param  Closure(): Response  $send
     */
    protected function send(Closure $send): Response
    {
        return $this->sendRequest($send);
    }

    /**
     * A repeated DELETE may receive 404/410 after the first request succeeded but its response was lost.
     *
     * @param  Closure(): Response  $send
     */
    protected function sendDeletion(Closure $send): ?Response
    {
        return $this->sendRequest($send, true);
    }

    /**
     * @param  Closure(): Response  $send
     */
    private function sendRequest(Closure $send, bool $deletion = false): ?Response
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            throw new RetryableSocialPublishException(
                'The social provider request could not be completed.',
                outcomeIsUncertain: true,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        if ($deletion && in_array($response->status(), [404, 410], true)) {
            return null;
        }

        if (in_array($response->status(), [408, 425, 429], true) || $response->serverError()) {
            throw new RetryableSocialPublishException(
                'The social provider is temporarily unavailable.',
                outcomeIsUncertain: $response->status() === 408 || $response->serverError(),
            );
        }

        if ($response->status() === 401) {
            throw new PermanentSocialPublishException(
                'The social provider authorization has expired.',
                errorCode: 'token_expired',
            );
        }

        if ($response->status() === 403) {
            throw new PermanentSocialPublishException(
                'The social provider denied the required publishing permission.',
                errorCode: 'permission_denied',
            );
        }

        throw new PermanentSocialPublishException('The social provider rejected the request.');
    }

    protected function requireRemoteId(mixed $remoteId, string $pattern): string
    {
        if (! is_string($remoteId) || preg_match($pattern, $remoteId) !== 1) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }

        return $remoteId;
    }

    protected function textOnlyContent(
        SocialPost $socialPost,
        Publication $publication,
        int $maximumCharacters,
    ): string {
        if ($publication->exists && $publication->media()->exists()) {
            throw new PermanentSocialPublishException(
                'This social provider adapter supports text-only publications.',
            );
        }

        $content = trim((string) $socialPost->content);
        if ($content === '') {
            throw new PermanentSocialPublishException('The social publication content is empty.');
        }

        if ($maximumCharacters < 1 || mb_strlen($content) > $maximumCharacters) {
            throw new PermanentSocialPublishException(
                'The social publication content exceeds this provider’s supported limit.',
            );
        }

        return $content;
    }
}
