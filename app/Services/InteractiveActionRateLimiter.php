<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

class InteractiveActionRateLimiter
{
    public function attachmentUpload(User $user): void
    {
        $this->consume($user, 'attachment-uploads', [
            ['maximum' => 10, 'decay' => 60, 'window' => 'minute'],
        ]);
    }

    public function exportRequest(User $user): void
    {
        $this->consume($user, 'exports', [
            ['maximum' => 5, 'decay' => 3600, 'window' => 'hour'],
            ['maximum' => 20, 'decay' => 86400, 'window' => 'day'],
        ]);
    }

    public function publicationAction(User $user): void
    {
        $this->consume($user, 'publication-actions', [
            ['maximum' => 20, 'decay' => 60, 'window' => 'minute'],
        ]);
    }

    public function shareAction(User $user): void
    {
        $this->consume($user, 'share-management', [
            ['maximum' => 20, 'decay' => 60, 'window' => 'minute'],
        ]);
    }

    public function socialAction(User $user): void
    {
        $this->consume($user, 'social-account-actions', [
            ['maximum' => 20, 'decay' => 60, 'window' => 'minute'],
        ]);
    }

    /**
     * @param  array<int, array{maximum: int, decay: int, window: string}>  $windows
     */
    private function consume(User $user, string $bucket, array $windows): void
    {
        foreach ($windows as $window) {
            $key = implode(':', [
                'memoria',
                'interactive',
                $bucket,
                $window['window'],
                (string) $user->getAuthIdentifier(),
            ]);

            $allowed = RateLimiter::attempt(
                $key,
                $window['maximum'],
                static fn (): bool => true,
                $window['decay'],
            );

            if ($allowed !== false) {
                continue;
            }

            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new ThrottleRequestsException(
                __('Too many sensitive actions. Please wait before trying again.'),
                headers: ['Retry-After' => (string) $retryAfter],
            );
        }
    }
}
