<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SocialHttpClient
{
    public function oauthForm(): PendingRequest
    {
        return Http::acceptJson()
            ->asForm()
            ->connectTimeout((int) config('memoria.social.connect_timeout_seconds', 3))
            ->timeout((int) config('memoria.social.timeout_seconds', 15))
            ->withOptions(['allow_redirects' => false]);
    }

    public function for(SocialAccount $account, ?string $idempotencyKey = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) config('memoria.social.connect_timeout_seconds', 3))
            ->timeout((int) config('memoria.social.timeout_seconds', 15))
            ->withOptions(['allow_redirects' => false])
            ->withToken($account->access_token);

        return $idempotencyKey === null
            ? $request
            : $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
    }
}
