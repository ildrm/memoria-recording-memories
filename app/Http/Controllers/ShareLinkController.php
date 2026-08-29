<?php

namespace App\Http\Controllers;

use App\Actions\CreateShareLink;
use App\Actions\RevokeShareLink;
use App\Http\Requests\StoreShareLinkRequest;
use App\Models\Entry;
use App\Models\ShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShareLinkController extends Controller
{
    public function store(
        StoreShareLinkRequest $request,
        Entry $entry,
        CreateShareLink $createShareLink,
    ): JsonResponse {
        $validated = $request->validated();
        $expiresAt = array_key_exists('expires_at', $validated)
            ? ($validated['expires_at'] === null
                ? null
                : CarbonImmutable::parse($validated['expires_at']))
            : CarbonImmutable::now()->addDays(max(
                1,
                (int) config('memoria.shares.default_expiration_days', 30),
            ));
        $created = $createShareLink->handle(
            entry: $entry,
            owner: $request->user(),
            label: $validated['label'] ?? null,
            password: $validated['password'] ?? null,
            expiresAt: $expiresAt,
            maxViews: isset($validated['max_views']) ? (int) $validated['max_views'] : null,
            trackViews: (bool) ($validated['track_views'] ?? false),
            includeAttachments: (bool) ($validated['include_attachments'] ?? false),
        );

        return response()->json(['data' => [
            'id' => $created->shareLink->getKey(),
            'url' => $created->url,
            'expires_at' => $created->shareLink->expires_at?->toIso8601String(),
        ]], 201);
    }

    public function destroy(
        Request $request,
        ShareLink $shareLink,
        RevokeShareLink $revokeShareLink,
    ): RedirectResponse {
        Gate::authorize('delete', $shareLink);
        $revokeShareLink->handle($shareLink, $request->user());

        return back()->with('status', __('Share link revoked.'));
    }
}
