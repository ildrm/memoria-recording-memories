<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShareLinkAccessSession
{
    public function isGranted(Request $request, string $token): bool
    {
        $grantedAt = $request->session()->get($this->key($token));
        $minutes = max(1, (int) config('memoria.shares.access_session_minutes', 60));

        return is_int($grantedAt)
            && $grantedAt >= now()->subMinutes($minutes)->getTimestamp();
    }

    public function grant(Request $request, string $token): void
    {
        $request->session()->put($this->key($token), now()->getTimestamp());
    }

    private function key(string $token): string
    {
        return 'memoria.share-access.'.hash_hmac(
            'sha256',
            $token,
            (string) config('app.key'),
        );
    }
}
