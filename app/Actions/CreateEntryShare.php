<?php

namespace App\Actions;

use App\Enums\SharePermission;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateEntryShare
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        Entry $entry,
        User $owner,
        User $recipient,
        ?DateTimeInterface $expiresAt = null,
        bool $includeAttachments = false,
    ): EntryShare {
        Gate::forUser($owner)->authorize('share', $entry);
        Gate::forUser($owner)->authorize('create', EntryShare::class);

        if ($owner->is($recipient)) {
            throw ValidationException::withMessages([
                'recipient_email' => [__('A memory cannot be shared back to its owner.')],
            ]);
        }

        $expiration = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt)->utc();
        $maximumExpiration = now()->addDays(
            (int) config('memoria.shares.maximum_expiration_days', 365),
        );
        if ($expiration !== null && ($expiration->isPast() || $expiration->greaterThan($maximumExpiration))) {
            throw ValidationException::withMessages([
                'expires_at' => [__('Choose a future expiration within the allowed sharing window.')],
            ]);
        }

        return DB::transaction(function () use (
            $entry,
            $owner,
            $recipient,
            $expiration,
            $includeAttachments,
        ): EntryShare {
            $entry = Entry::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            $share = EntryShare::query()
                ->whereBelongsTo($entry)
                ->whereBelongsTo($recipient, 'recipient')
                ->lockForUpdate()
                ->first() ?? new EntryShare;
            $share->forceFill([
                'entry_id' => $entry->getKey(),
                'shared_by_user_id' => $owner->getKey(),
                'shared_with_user_id' => $recipient->getKey(),
                'permission' => SharePermission::View,
                'include_attachments' => $includeAttachments,
                'expires_at' => $expiration,
                'revoked_at' => null,
            ]);
            $share->save();

            $this->auditRecorder->record(
                event: 'entry_share.created',
                actor: $owner,
                auditable: $share,
                metadata: [
                    'entry_id' => $entry->getKey(),
                    'recipient_user_id' => $recipient->getKey(),
                    'permission' => SharePermission::View->value,
                    'include_attachments' => $includeAttachments,
                    'has_expiration' => $expiration !== null,
                ],
            );

            return $share->refresh();
        });
    }
}
