<?php

namespace App\Actions;

use App\Models\EntryShare;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RevokeEntryShare
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(EntryShare $share, User $owner): EntryShare
    {
        Gate::forUser($owner)->authorize('delete', $share);

        return DB::transaction(function () use ($share, $owner): EntryShare {
            $share = EntryShare::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($share->getKey());

            if ($share->revoked_at === null) {
                $share->forceFill(['revoked_at' => now()])->save();
                $this->auditRecorder->record(
                    event: 'entry_share.revoked',
                    actor: $owner,
                    auditable: $share,
                    metadata: [
                        'entry_id' => $share->entry_id,
                        'recipient_user_id' => $share->shared_with_user_id,
                    ],
                );
            }

            return $share->refresh();
        });
    }
}
