<?php

namespace App\Http\Controllers;

use App\Actions\CreateEntryShare;
use App\Actions\RevokeEntryShare;
use App\Http\Requests\StoreEntryShareRequest;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EntryShareController extends Controller
{
    public function store(
        StoreEntryShareRequest $request,
        Entry $entry,
        CreateEntryShare $createEntryShare,
    ): JsonResponse|RedirectResponse {
        $recipient = User::query()
            ->where('email', $request->validated('recipient_email'))
            ->whereNull('disabled_at')
            ->first();

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'recipient_email' => [__('No active account can receive this memory at that address.')],
            ]);
        }

        $expiresAt = $request->validated('expires_at');
        $share = $createEntryShare->handle(
            entry: $entry,
            owner: $request->user(),
            recipient: $recipient,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::parse($expiresAt),
            includeAttachments: (bool) ($request->validated('include_attachments') ?? false),
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $share->getKey(),
                'recipient_user_id' => $recipient->getKey(),
                'recipient_name' => $recipient->name,
                'expires_at' => $share->expires_at === null
                    ? null
                    : CarbonImmutable::parse($share->expires_at)->toIso8601String(),
                'include_attachments' => (bool) $share->include_attachments,
            ]], 201);
        }

        return back()->with('status', __('Memory shared for view-only access.'));
    }

    public function destroy(
        Request $request,
        EntryShare $entryShare,
        RevokeEntryShare $revokeEntryShare,
    ): RedirectResponse {
        $revokeEntryShare->handle($entryShare, $request->user());

        return back()->with('status', __('Registered-user access was revoked.'));
    }
}
