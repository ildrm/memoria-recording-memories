<?php

namespace App\Services;

use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PublicationTargetConfigurator
{
    /**
     * @param  array<int, SocialProvider|string>  $socialProviders
     * @param  array<string, string|null>  $providerText
     * @param  array<int, int|string>  $socialAccountIds
     * @return Collection<int, PublicationTarget>
     */
    public function configure(
        Publication $publication,
        bool $publishToWebsite,
        array $socialProviders,
        array $providerText,
        PublicationTargetStatus $status,
        mixed $scheduledAt = null,
        array $socialAccountIds = [],
    ): Collection {
        $targetKeys = [];
        $targets = new Collection;

        if ($publishToWebsite) {
            $website = $this->target($publication, 'website');
            $website->forceFill([
                'user_id' => $publication->user_id,
                'type' => PublicationTargetType::Website,
                'provider' => null,
                'social_account_id' => null,
                'status' => $status,
                'content_override' => null,
                'scheduled_at' => $scheduledAt,
                'failed_at' => null,
            ])->save();

            $targetKeys[] = 'website';
            $targets->push($website);
        }

        foreach ($this->selectedAccounts($publication, $socialProviders, $socialAccountIds) as $account) {
            $provider = $account->provider;

            $targetKey = "social:{$provider->value}:{$account->getKey()}";
            $target = $this->target($publication, $targetKey);
            $target->forceFill([
                'user_id' => $publication->user_id,
                'social_account_id' => $account->getKey(),
                'type' => PublicationTargetType::Social,
                'provider' => $provider,
                'status' => $status,
                'content_override' => $providerText[$provider->value] ?? null,
                'scheduled_at' => $scheduledAt,
                'failed_at' => null,
            ])->save();

            $targetKeys[] = $targetKey;
            $targets->push($target);
        }

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'targets' => ['Choose the public website or at least one connected social account.'],
            ]);
        }

        $publication->targets()
            ->whereNotIn('target_key', $targetKeys)
            ->whereIn('status', [
                PublicationTargetStatus::Pending,
                PublicationTargetStatus::Scheduled,
                PublicationTargetStatus::Processing,
            ])
            ->update([
                'status' => PublicationTargetStatus::Cancelled->value,
                'scheduled_at' => null,
            ]);

        return $targets;
    }

    /**
     * @param  array<int, SocialProvider|string>  $socialProviders
     * @param  array<int, int|string>  $socialAccountIds
     * @return Collection<int, SocialAccount>
     */
    private function selectedAccounts(
        Publication $publication,
        array $socialProviders,
        array $socialAccountIds,
    ): Collection {
        if (($socialProviders !== [] || $socialAccountIds !== [])
            && ! in_array(config('memoria.social.driver'), ['real', 'fake'], true)) {
            throw ValidationException::withMessages([
                'social_account_ids' => [__('Social delivery is not enabled on this installation.')],
            ]);
        }

        $accounts = new Collection;
        $normalizedIds = collect($socialAccountIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($normalizedIds->count() !== count($socialAccountIds)) {
            throw ValidationException::withMessages([
                'social_account_ids' => [__('Choose each connected account only once.')],
            ]);
        }

        if ($normalizedIds->isNotEmpty()) {
            $selected = SocialAccount::query()
                ->ownedBy($publication->user_id)
                ->whereKey($normalizedIds->all())
                ->get();

            if ($selected->count() !== $normalizedIds->count()) {
                throw ValidationException::withMessages([
                    'social_account_ids' => [__('One of the selected social accounts is unavailable.')],
                ]);
            }

            foreach ($normalizedIds as $accountId) {
                $account = $selected->firstWhere('id', $accountId);
                if ($account instanceof SocialAccount) {
                    $this->ensureAccountIsConnected($account, 'social_account_ids');
                    $accounts->push($account);
                }
            }
        }

        foreach ($socialProviders as $providerValue) {
            $provider = $providerValue instanceof SocialProvider
                ? $providerValue
                : SocialProvider::from($providerValue);
            $matches = SocialAccount::query()
                ->ownedBy($publication->user_id)
                ->where('provider', $provider)
                ->whereNull('revoked_at')
                ->get()
                ->filter(fn (SocialAccount $account): bool => $account->isConnected());

            if ($matches->isEmpty()) {
                throw ValidationException::withMessages([
                    'social_providers' => [__('Connect an active :provider account before publishing.', ['provider' => $provider->label()])],
                ]);
            }

            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'social_providers' => [__('More than one :provider account is connected. Select the exact account instead of a provider name.', ['provider' => $provider->label()])],
                ]);
            }

            $account = $matches->first();
            if (! $accounts->contains('id', $account->getKey())) {
                $this->ensureAccountIsConnected($account, 'social_providers');
                $accounts->push($account);
            }
        }

        return $accounts;
    }

    private function ensureAccountIsConnected(SocialAccount $account, string $errorKey): void
    {
        if (! $account->isConnected()) {
            throw ValidationException::withMessages([
                $errorKey => [__('Reconnect :account before publishing.', [
                    'account' => $account->display_name ?: $account->username ?: $account->provider->label(),
                ])],
            ]);
        }

        if ($account->provider === SocialProvider::Facebook && ! $this->hasValidFacebookPageId($account)) {
            throw ValidationException::withMessages([
                $errorKey => [__('Select a Facebook Page and Page access token before publishing.')],
            ]);
        }

        if ($account->provider === SocialProvider::Mastodon && ! $this->hasSafeMastodonOrigin($account)) {
            throw ValidationException::withMessages([
                $errorKey => [__('Configure a safe HTTPS Mastodon instance for this account before publishing.')],
            ]);
        }
    }

    private function hasValidFacebookPageId(SocialAccount $account): bool
    {
        $pageId = data_get($account->metadata, 'page_id');

        return (is_string($pageId) || is_int($pageId))
            && preg_match('/\A[0-9]+\z/', (string) $pageId) === 1;
    }

    private function hasSafeMastodonOrigin(SocialAccount $account): bool
    {
        if (! is_string($account->server_url) || filter_var($account->server_url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($account->server_url);
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

    private function target(Publication $publication, string $targetKey): PublicationTarget
    {
        $target = PublicationTarget::query()
            ->whereBelongsTo($publication)
            ->where('target_key', $targetKey)
            ->first() ?? new PublicationTarget;

        return $target->forceFill([
            'publication_id' => $publication->getKey(),
            'target_key' => $targetKey,
        ]);
    }
}
