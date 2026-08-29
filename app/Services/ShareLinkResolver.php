<?php

namespace App\Services;

use App\Models\ShareLink;
use App\Services\ShareLinks\InvalidShareLink;
use App\Services\ShareLinks\InvalidSharePassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ShareLinkResolver
{
    public function resolve(
        string $token,
        ?string $password = null,
        bool $recordView = true,
        bool $passwordVerified = false,
        bool $viewAlreadyCounted = false,
    ): ShareLink {
        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use (
            $tokenHash,
            $password,
            $recordView,
            $passwordVerified,
            $viewAlreadyCounted,
        ): ShareLink {
            $shareLink = ShareLink::query()
                ->with(['entry', 'publication'])
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($shareLink === null
                || $shareLink->revoked_at !== null
                || ($shareLink->expires_at !== null && $shareLink->expires_at->isPast())
                || (! $viewAlreadyCounted
                    && $shareLink->max_views !== null
                    && $shareLink->view_count >= $shareLink->max_views)
            ) {
                throw new InvalidShareLink;
            }

            if ($shareLink->password_hash !== null
                && ! $passwordVerified
                && ($password === null || ! Hash::check($password, $shareLink->password_hash))
            ) {
                throw new InvalidSharePassword($shareLink);
            }

            if ($recordView && ($shareLink->track_views || $shareLink->max_views !== null)) {
                $shareLink->increment('view_count');
            }

            $shareLink->forceFill(['last_accessed_at' => now()])->save();

            return $shareLink->refresh()->loadMissing(['entry', 'publication']);
        });
    }
}
