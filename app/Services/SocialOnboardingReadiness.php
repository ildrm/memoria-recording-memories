<?php

namespace App\Services;

use App\Enums\SocialProvider;

class SocialOnboardingReadiness
{
    /**
     * @return array{available: bool, message: string}
     */
    public function for(SocialProvider $provider): array
    {
        if (config('memoria.social.driver') !== 'real') {
            return [
                'available' => false,
                'message' => __('External social publishing is not enabled on this installation. No provider sign-in will be started.'),
            ];
        }

        if ($provider === SocialProvider::Facebook) {
            return [
                'available' => false,
                'message' => __('Facebook Pages onboarding requires explicit Page selection and a Page access token. That flow is not configured, so Memoria will not start a personal-profile OAuth flow or ask for a password.'),
            ];
        }

        if ($provider === SocialProvider::Mastodon) {
            return [
                'available' => false,
                'message' => __('Mastodon onboarding requires instance-specific OAuth application registration. That flow is not configured, so Memoria will not ask for an instance password.'),
            ];
        }

        $configuration = config("memoria.social.providers.{$provider->value}");
        $driver = is_array($configuration) ? ($configuration['socialite_driver'] ?? null) : null;

        if (! is_string($driver) || $driver === '' || ! $this->credentialsAreConfigured($driver)) {
            return [
                'available' => false,
                'message' => __('OAuth client credentials and a callback URL have not been configured for this provider.'),
            ];
        }

        return [
            'available' => true,
            'message' => __('Memoria will redirect to the provider’s OAuth consent screen. It never asks for or stores your provider password.'),
        ];
    }

    private function credentialsAreConfigured(string $driver): bool
    {
        $configuration = config("services.{$driver}");

        return is_array($configuration)
            && filled($configuration['client_id'] ?? null)
            && filled($configuration['client_secret'] ?? null)
            && filled($configuration['redirect'] ?? null);
    }
}
