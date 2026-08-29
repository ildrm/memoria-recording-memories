<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use Illuminate\Http\Client\PendingRequest;

class LinkedInSocialPublisher extends ProviderSocialPublisher
{
    private const API_URL = 'https://api.linkedin.com/rest/posts';

    public function supports(SocialProvider $provider): bool
    {
        return $provider === SocialProvider::LinkedIn;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        $content = $this->textOnlyContent($socialPost, $publication, 3000);
        $response = $this->send(
            fn () => $this->clientFor($account)->post(self::API_URL, [
                'author' => $this->authorUrn($account),
                'commentary' => $content,
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ]),
        );
        $remoteId = $this->requireRemoteId(
            $response->header('x-restli-id'),
            '/\Aurn:li:(?:share|ugcPost):[0-9]+\z/',
        );

        return new SocialPublishResult(
            remoteId: $remoteId,
            remoteUrl: "https://www.linkedin.com/feed/update/{$remoteId}",
        );
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        $remoteId = $this->requireRemoteId(
            $socialPost->remote_post_id,
            '/\Aurn:li:(?:share|ugcPost):[0-9]+\z/',
        );

        $this->sendDeletion(fn () => $this->clientFor($account)
            ->withHeaders(['X-RestLi-Method' => 'DELETE'])
            ->delete(self::API_URL.'/'.rawurlencode($remoteId)));
    }

    private function clientFor(SocialAccount $account): PendingRequest
    {
        $version = config('memoria.social.linkedin_version');
        if (! is_string($version) || preg_match('/\A[0-9]{6}\z/', $version) !== 1) {
            throw new PermanentSocialPublishException('The LinkedIn publishing version is not configured.');
        }

        return $this->client($account)->withHeaders([
            'LinkedIn-Version' => $version,
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }

    private function authorUrn(SocialAccount $account): string
    {
        $providerUserId = $account->provider_user_id;
        if (! is_string($providerUserId) || trim($providerUserId) === '') {
            throw new PermanentSocialPublishException('The LinkedIn author is not configured.');
        }

        $authorUrn = str_starts_with($providerUserId, 'urn:li:person:')
            ? $providerUserId
            : 'urn:li:person:'.$providerUserId;

        if (preg_match('/\Aurn:li:person:[A-Za-z0-9_-]+\z/', $authorUrn) !== 1) {
            throw new PermanentSocialPublishException('The LinkedIn author is not configured.');
        }

        return $authorUrn;
    }
}
