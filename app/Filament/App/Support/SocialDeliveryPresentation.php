<?php

namespace App\Filament\App\Support;

use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Icons\Heroicon;
use Throwable;

class SocialDeliveryPresentation
{
    public static function label(SocialPost $post): string
    {
        $status = self::status($post);

        if (config('memoria.social.driver') === 'fake' && $status === SocialPostStatus::Published) {
            return __('Simulated success');
        }

        return match ($status) {
            SocialPostStatus::Pending => __('Pending'),
            SocialPostStatus::Scheduled => __('Scheduled'),
            SocialPostStatus::Processing => __('Processing'),
            SocialPostStatus::Published => __('Published'),
            SocialPostStatus::Retrying => __('Retrying'),
            SocialPostStatus::Failed => __('Failed'),
            SocialPostStatus::Cancelled => __('Cancelled'),
            SocialPostStatus::Disconnected => __('Disconnected'),
            SocialPostStatus::TokenExpired => __('Token expired'),
            SocialPostStatus::DeletionPending => __('Removal requested'),
            SocialPostStatus::Deleted => __('Removed from provider'),
            SocialPostStatus::DeletionFailed => __('Removal failed'),
            null => __('Unknown'),
        };
    }

    public static function description(SocialPost $post): string
    {
        $status = self::status($post);
        $scheduledAt = self::date($post->scheduled_at);
        $nextRetryAt = self::date($post->next_retry_at);

        return match ($status) {
            SocialPostStatus::Pending => __('Waiting for a social queue worker.'),
            SocialPostStatus::Scheduled => $scheduledAt
                ? __('Waiting until :time.', ['time' => $scheduledAt->translatedFormat('M j, Y H:i T')])
                : __('Waiting for its scheduled dispatch.'),
            SocialPostStatus::Processing => __('A request is currently in progress. Do not submit a duplicate.'),
            SocialPostStatus::Published => config('memoria.social.driver') === 'fake'
                ? __('Simulation confirmed; no external post was created.')
                : __('The provider confirmed this post.'),
            SocialPostStatus::Retrying => $nextRetryAt
                ? __('Automatic retry planned :time.', ['time' => $nextRetryAt->diffForHumans()])
                : __('A safe automatic retry is pending.'),
            SocialPostStatus::Failed => __('Delivery stopped after a permanent error or exhausted retries.'),
            SocialPostStatus::Cancelled => __('Delivery was cancelled before provider confirmation.'),
            SocialPostStatus::Disconnected => __('The selected account was disconnected. Reconnect that identity first.'),
            SocialPostStatus::TokenExpired => __('The selected account token expired. Reconnect that identity first.'),
            SocialPostStatus::DeletionPending => __('Asynchronous provider removal is pending. The external copy may still be visible.'),
            SocialPostStatus::Deleted => __('The provider confirmed removal. Third-party copies or caches may still exist.'),
            SocialPostStatus::DeletionFailed => __('Automatic provider removal stopped. The external copy may remain.'),
            null => __('This delivery has an unrecognized state. Do not assume it was published.'),
        };
    }

    public static function color(SocialPost $post): string
    {
        return match (self::status($post)) {
            SocialPostStatus::Published => config('memoria.social.driver') === 'fake' ? 'info' : 'success',
            SocialPostStatus::Processing, SocialPostStatus::Retrying, SocialPostStatus::Scheduled => 'warning',
            SocialPostStatus::Failed, SocialPostStatus::TokenExpired => 'danger',
            SocialPostStatus::DeletionFailed => 'danger',
            SocialPostStatus::DeletionPending => 'warning',
            SocialPostStatus::Deleted => 'success',
            SocialPostStatus::Disconnected, SocialPostStatus::Cancelled => 'gray',
            default => 'info',
        };
    }

    public static function icon(SocialPost $post): Heroicon
    {
        return match (self::status($post)) {
            SocialPostStatus::Published => Heroicon::OutlinedCheckCircle,
            SocialPostStatus::Processing => Heroicon::OutlinedArrowPath,
            SocialPostStatus::Retrying => Heroicon::OutlinedClock,
            SocialPostStatus::Scheduled => Heroicon::OutlinedCalendarDays,
            SocialPostStatus::Failed => Heroicon::OutlinedXCircle,
            SocialPostStatus::Disconnected => Heroicon::OutlinedLinkSlash,
            SocialPostStatus::TokenExpired => Heroicon::OutlinedKey,
            SocialPostStatus::Cancelled => Heroicon::OutlinedNoSymbol,
            SocialPostStatus::DeletionPending => Heroicon::OutlinedClock,
            SocialPostStatus::Deleted => Heroicon::OutlinedTrash,
            SocialPostStatus::DeletionFailed => Heroicon::OutlinedExclamationTriangle,
            default => Heroicon::OutlinedPaperAirplane,
        };
    }

    public static function safeRemoteUrl(SocialPost $post): ?string
    {
        $url = $post->remote_url;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $account = $post->socialAccount;

        $isAllowed = match (self::provider($post)) {
            SocialProvider::X => self::isStandardHttpsPort($port)
                && in_array($host, ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], true),
            SocialProvider::LinkedIn => self::isStandardHttpsPort($port)
                && in_array($host, ['linkedin.com', 'www.linkedin.com'], true),
            SocialProvider::Facebook => self::isStandardHttpsPort($port)
                && in_array($host, ['facebook.com', 'www.facebook.com'], true),
            SocialProvider::Mastodon => $account instanceof SocialAccount
                && SocialAccountPresentation::configurationIssue($account) === null
                && self::origin($account->server_url) === self::origin($url),
            null => false,
        };

        return $isAllowed ? $url : null;
    }

    private static function status(SocialPost $post): ?SocialPostStatus
    {
        $status = $post->status;

        return $status instanceof SocialPostStatus
            ? $status
            : (is_string($status) ? SocialPostStatus::tryFrom($status) : null);
    }

    private static function provider(SocialPost $post): ?SocialProvider
    {
        $provider = $post->provider;

        return $provider instanceof SocialProvider
            ? $provider
            : (is_string($provider) ? SocialProvider::tryFrom($provider) : null);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function origin(?string $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port;
    }

    private static function isStandardHttpsPort(?int $port): bool
    {
        return $port === null || $port === 443;
    }
}
