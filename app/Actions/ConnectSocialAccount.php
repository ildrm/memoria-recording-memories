<?php

namespace App\Actions;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as SocialiteUser;

class ConnectSocialAccount
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        User $owner,
        SocialProvider $provider,
        SocialiteUser $providerUser,
    ): SocialAccount {
        if (! is_string($providerUser->token) || trim($providerUser->token) === '') {
            throw ValidationException::withMessages([
                'social_account' => [__('The provider did not return a usable access token.')],
            ]);
        }

        $account = SocialAccount::withTrashed()
            ->where('user_id', $owner->getKey())
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $providerUser->getId())
            ->first() ?? new SocialAccount;

        if ($account->trashed()) {
            $account->restore();
        }

        $expiresIn = is_numeric($providerUser->expiresIn) ? (int) $providerUser->expiresIn : 0;

        $account->forceFill([
            'user_id' => $owner->getKey(),
            'provider' => $provider,
            'provider_user_id' => (string) $providerUser->getId(),
            'username' => $providerUser->getNickname(),
            'display_name' => $providerUser->getName(),
            'access_token' => $providerUser->token,
            'refresh_token' => $providerUser->refreshToken,
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'scopes' => Arr::wrap($providerUser->approvedScopes),
            'metadata' => [],
            'connected_at' => $account->connected_at ?? now(),
            'last_refreshed_at' => now(),
            'revoked_at' => null,
        ]);
        $account->save();

        $this->auditRecorder->record(
            event: 'social_account.connected',
            actor: $owner,
            auditable: $account,
            metadata: ['provider' => $provider->value],
        );

        return $account;
    }
}
