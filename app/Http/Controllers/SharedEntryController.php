<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SharedEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $recipient = $request->user();
        abort_unless($recipient instanceof User, 401);

        $shares = EntryShare::query()
            ->active()
            ->whereBelongsTo($recipient, 'recipient')
            ->join('entries', 'entries.id', '=', 'entry_shares.entry_id')
            ->join('users', 'users.id', '=', 'entry_shares.shared_by_user_id')
            ->whereNull('entries.deleted_at')
            ->select([
                'entry_shares.*',
                'entries.title as entry_title',
                'entries.occurred_at as entry_occurred_at',
                'users.name as owner_name',
            ])
            ->orderByDesc('entry_shares.created_at')
            ->paginate(20)
            ->through(fn (EntryShare $share): array => [
                'id' => $share->getKey(),
                'entry_id' => $share->entry_id,
                'title' => $share->getAttribute('entry_title'),
                'occurred_at' => $this->date($share->getAttribute('entry_occurred_at')),
                'shared_by' => $share->getAttribute('owner_name'),
                'include_attachments' => (bool) $share->include_attachments,
                'expires_at' => $this->date($share->expires_at),
                'show_url' => route('entries.shared.show', $share->entry_id),
            ]);

        return response()->json($shares);
    }

    public function show(Request $request, Entry $entry): JsonResponse
    {
        $recipient = $request->user();
        abort_unless($recipient instanceof User, 401);

        $share = EntryShare::query()
            ->active()
            ->whereBelongsTo($recipient, 'recipient')
            ->whereBelongsTo($entry)
            ->firstOrFail();
        Gate::forUser($recipient)->authorize('view', $entry);

        $attachments = $share->include_attachments
            ? Attachment::query()
                ->whereBelongsTo($entry)
                ->where('scan_status', AttachmentScanStatus::Clean)
                ->orderBy('id')
                ->get()
                ->map(fn (Attachment $attachment): array => [
                    'id' => $attachment->getKey(),
                    'name' => $attachment->download_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'download_url' => route('attachments.download', $attachment),
                ])
                ->all()
            : [];

        return response()->json(['data' => [
            'share_id' => $share->getKey(),
            'entry' => [
                'id' => $entry->getKey(),
                'title' => $entry->title,
                'body' => $entry->body,
                'occurred_at' => $this->date($entry->occurred_at),
                'timezone' => $entry->timezone,
                'mood' => $entry->mood instanceof \BackedEnum ? $entry->mood->value : $entry->mood,
                'custom_mood' => $entry->custom_mood,
                'location_name' => $entry->location_name,
                'importance' => $entry->importance,
            ],
            'attachments' => $attachments,
        ]]);
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
