<?php

namespace App\Filament\App\Support;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class SocialAccountPresentation
{
    /**
     * @return array{label: string, description: string, color: string, icon: Heroicon}
     */
    public static function state(SocialAccount $account): array
    {
        if ($account->revoked_at !== null) {
            return self::result(
                __('Disconnected'),
                __('Pending deliveries were stopped. Published posts may remain at the provider.'),
                'gray',
                Heroicon::OutlinedLinkSlash,
            );
        }

        if ($account->token_expires_at?->isPast()) {
            return self::result(
                __('Token expired'),
                __('Reconnect with the same provider identity before retrying a delivery.'),
                'warning',
                Heroicon::OutlinedKey,
            );
        }

        $configurationIssue = self::configurationIssue($account);
        if ($configurationIssue !== null) {
            return self::result(
                __('Setup incomplete'),
                $configurationIssue,
                'warning',
                Heroicon::OutlinedExclamationTriangle,
            );
        }

        return match (config('memoria.social.driver')) {
            'real' => self::result(
                __('Connected'),
                __('External delivery is enabled. Provider acceptance is still reported per post.'),
                'success',
                Heroicon::OutlinedCheckCircle,
            ),
            'fake' => self::result(
                __('Simulation only'),
                __('Local/test delivery is deterministic and does not create an external post.'),
                'info',
                Heroicon::OutlinedBeaker,
            ),
            default => self::result(
                __('Delivery unavailable'),
                __('This installation has no enabled social delivery driver.'),
                'gray',
                Heroicon::OutlinedNoSymbol,
            ),
        };
    }

    public static function isReadyForDelivery(SocialAccount $account): bool
    {
        return $account->isConnected()
            && self::configurationIssue($account) === null
            && in_array(config('memoria.social.driver'), ['real', 'fake'], true);
    }

    public static function label(SocialAccount $account): string
    {
        $displayName = is_string($account->display_name) ? trim($account->display_name) : '';
        $username = is_string($account->username) ? ltrim(trim($account->username), '@') : '';
        $identity = match (true) {
            $displayName !== '' && $username !== '' => $displayName.' (@'.$username.')',
            $displayName !== '' => $displayName,
            $username !== '' => '@'.$username,
            default => __('Unnamed identity'),
        };
        $provider = $account->provider;
        $providerLabel = $provider instanceof SocialProvider ? $provider->label() : Str::headline((string) $provider);
        $connection = $account->exists
            ? __('connection :id', ['id' => $account->getKey()])
            : __('new connection');

        return $providerLabel.' · '.$identity.' · '.$connection;
    }

    public static function configurationIssue(SocialAccount $account): ?string
    {
        return match ($account->provider) {
            SocialProvider::Facebook => self::hasValidFacebookPageId($account)
                ? null
                : __('No Facebook Page and Page access token were selected. A personal Facebook connection cannot publish a Page post.'),
            SocialProvider::Mastodon => self::isSafeOrigin($account->server_url)
                ? null
                : __('A safe HTTPS Mastodon instance origin has not been configured.'),
            default => filled($account->provider_user_id)
                ? null
                : __('The provider identity is incomplete. Reconnect before publishing.'),
        };
    }

    private static function isSafeOrigin(?string $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        return $host !== ''
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/(?:\A|\.)(?:localhost|local|internal|test|invalid|example)\z/i', $host) !== 1
            && preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/i', $host) !== 1;
    }

    private static function hasValidFacebookPageId(SocialAccount $account): bool
    {
        $pageId = data_get($account->metadata, 'page_id');

        return (is_string($pageId) || is_int($pageId))
            && preg_match('/\A[0-9]+\z/', (string) $pageId) === 1;
    }

    /**
     * @return array{label: string, description: string, color: string, icon: Heroicon}
     */
    private static function result(string $label, string $description, string $color, Heroicon $icon): array
    {
        return compact('label', 'description', 'color', 'icon');
    }
}
