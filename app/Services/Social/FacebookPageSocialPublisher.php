<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;

class FacebookPageSocialPublisher extends ProviderSocialPublisher
{
    public function supports(SocialProvider $provider): bool
    {
        return $provider === SocialProvider::Facebook;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        $pageId = $this->pageId($account);
        $content = $this->textOnlyContent($socialPost, $publication, 5000);
        $response = $this->send(
            fn () => $this->client($account)->post($this->apiUrl("{$pageId}/feed"), [
                'message' => $content,
            ]),
        );
        $remoteId = $this->requireRemoteId($response->json('id'), '/\A[0-9]+_[0-9]+\z/');
        [$remotePageId, $postId] = explode('_', $remoteId, 2);
        if ($remotePageId !== $pageId) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }

        return new SocialPublishResult(
            remoteId: $remoteId,
            remoteUrl: "https://www.facebook.com/{$pageId}/posts/{$postId}",
        );
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        $pageId = $this->pageId($account);
        $remoteId = $this->requireRemoteId($socialPost->remote_post_id, '/\A[0-9]+_[0-9]+\z/');
        [$remotePageId] = explode('_', $remoteId, 2);
        if ($remotePageId !== $pageId) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }

        $response = $this->sendDeletion(fn () => $this->client($account)->delete($this->apiUrl($remoteId)));

        if ($response !== null && $response->json('success') !== true) {
            throw new PermanentSocialPublishException('The social provider returned an invalid result.');
        }
    }

    private function pageId(SocialAccount $account): string
    {
        $pageId = data_get($account->metadata, 'page_id');
        if ((! is_string($pageId) && ! is_int($pageId)) || preg_match('/\A[0-9]+\z/', (string) $pageId) !== 1) {
            throw new PermanentSocialPublishException('The Facebook Page publishing account is not configured.');
        }

        return (string) $pageId;
    }

    private function apiUrl(string $path): string
    {
        $version = config('memoria.social.facebook_graph_version');
        if (! is_string($version) || preg_match('/\Av[0-9]+\.[0-9]+\z/', $version) !== 1) {
            throw new PermanentSocialPublishException('The Facebook Graph API version is not configured.');
        }

        return "https://graph.facebook.com/{$version}/{$path}";
    }
}
