<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;

class XSocialPublisher extends ProviderSocialPublisher
{
    private const API_URL = 'https://api.x.com/2/tweets';

    public function supports(SocialProvider $provider): bool
    {
        return $provider === SocialProvider::X;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        $content = $this->textOnlyContent($socialPost, $publication, 140);
        $response = $this->send(
            fn () => $this->client($account)->post(self::API_URL, [
                'text' => $content,
            ]),
        );
        $remoteId = $this->requireRemoteId($response->json('data.id'), '/\A[0-9]{1,19}\z/');

        return new SocialPublishResult(
            remoteId: $remoteId,
            remoteUrl: "https://x.com/i/web/status/{$remoteId}",
        );
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        $remoteId = $this->requireRemoteId($socialPost->remote_post_id, '/\A[0-9]{1,19}\z/');

        $response = $this->sendDeletion(fn () => $this->client($account)->delete(self::API_URL."/{$remoteId}"));

        if ($response !== null && $response->json('data.deleted') !== true) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }
    }
}
