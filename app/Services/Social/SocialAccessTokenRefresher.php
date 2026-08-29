<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

class SocialAccessTokenRefresher
{
    private const X_TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    public function __construct(private readonly SocialHttpClient $http) {}

    public function refreshIfExpired(SocialAccount $account): SocialAccount
    {
        if ($account->token_expires_at === null || $account->token_expires_at->isFuture()) {
            return $account;
        }

        if ($account->provider !== SocialProvider::X) {
            throw new PermanentSocialPublishException(
                'This social account must be reconnected before publishing.',
            );
        }

        $refreshToken = $account->refresh_token;
        $clientId = config('services.x.client_id');
        $clientSecret = config('services.x.client_secret');
        if (! is_string($refreshToken) || trim($refreshToken) === ''
            || ! is_string($clientId) || trim($clientId) === '') {
            throw new PermanentSocialPublishException(
                'This social account must be reconnected before publishing.',
            );
        }

        $request = $this->http->oauthForm();
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        if (is_string($clientSecret) && trim($clientSecret) !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $payload['client_id'] = $clientId;
        }

        $response = $this->send($request, $payload);
        $accessToken = $response->json('access_token');
        $rotatedRefreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($accessToken) || trim($accessToken) === ''
            || ! is_int($expiresIn) || $expiresIn < 1 || $expiresIn > 31536000
            || ($rotatedRefreshToken !== null
                && (! is_string($rotatedRefreshToken) || trim($rotatedRefreshToken) === ''))) {
            throw new PermanentSocialPublishException(
                'The social provider returned an invalid credential result.',
            );
        }

        return DB::transaction(function () use (
            $account,
            $refreshToken,
            $accessToken,
            $rotatedRefreshToken,
            $expiresIn,
        ): SocialAccount {
            $lockedAccount = SocialAccount::query()->lockForUpdate()->find($account->getKey());
            if (! $lockedAccount instanceof SocialAccount || $lockedAccount->revoked_at !== null) {
                throw new PermanentSocialPublishException(
                    'This social account must be reconnected before publishing.',
                );
            }

            if ($lockedAccount->token_expires_at?->isFuture()) {
                return $lockedAccount;
            }

            if (! is_string($lockedAccount->refresh_token)
                || ! hash_equals($refreshToken, $lockedAccount->refresh_token)) {
                throw new PermanentSocialPublishException(
                    'This social account must be reconnected before publishing.',
                );
            }

            $lockedAccount->forceFill([
                'access_token' => $accessToken,
                'refresh_token' => $rotatedRefreshToken ?? $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'last_refreshed_at' => now(),
            ])->save();

            return $lockedAccount;
        });
    }

    /** @param array<string, string> $payload */
    private function send(PendingRequest $request, array $payload): Response
    {
        try {
            $response = $request->post(self::X_TOKEN_URL, $payload);
        } catch (ConnectionException) {
            throw new RetryableSocialPublishException(
                'The social provider credential refresh could not be completed.',
            );
        }

        if ($response->successful()) {
            return $response;
        }

        if (in_array($response->status(), [408, 425, 429], true) || $response->serverError()) {
            throw new RetryableSocialPublishException(
                'The social provider credential refresh is temporarily unavailable.',
            );
        }

        throw new PermanentSocialPublishException(
            'This social account must be reconnected before publishing.',
        );
    }
}
