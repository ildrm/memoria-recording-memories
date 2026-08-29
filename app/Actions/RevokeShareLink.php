<?php

namespace App\Actions;

use App\Models\ShareLink;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\Gate;

class RevokeShareLink
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(ShareLink $shareLink, User $owner): ShareLink
    {
        Gate::forUser($owner)->authorize('delete', $shareLink);

        if ($shareLink->revoked_at === null) {
            $shareLink->forceFill(['revoked_at' => now()])->save();

            $this->auditRecorder->record(
                event: 'share_link.revoked',
                actor: $owner,
                auditable: $shareLink,
            );
        }

        return $shareLink;
    }
}
