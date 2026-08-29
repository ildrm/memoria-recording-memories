<?php

namespace App\Actions;

use App\Models\Attachment;
use App\Models\PublicationMedia;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteAttachment
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(Attachment $attachment, User $owner): void
    {
        Gate::forUser($owner)->authorize('delete', $attachment);

        DB::transaction(function () use ($attachment, $owner): void {
            $attachment = Attachment::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($attachment->getKey());
            Gate::forUser($owner)->authorize('delete', $attachment);

            $publicDerivatives = PublicationMedia::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('source_attachment_id', $attachment->getKey())
                ->lockForUpdate()
                ->get(['id', 'disk', 'path']);

            if ($publicDerivatives->contains(fn (PublicationMedia $medium): bool => $medium->disk === $attachment->disk && $medium->path === $attachment->path)) {
                throw ValidationException::withMessages([
                    'attachment' => [__('This attachment has a legacy public reference and cannot be removed until an independent public copy is created.')],
                ]);
            }

            $disk = $attachment->disk;
            $path = $attachment->path;
            $attachmentId = (int) $attachment->getKey();
            $derivativeCount = $publicDerivatives->count();

            PublicationMedia::query()
                ->whereIn('id', $publicDerivatives->modelKeys())
                ->update(['source_attachment_id' => null]);

            $this->auditRecorder->record(
                event: 'attachment.deleted',
                actor: $owner,
                auditable: $attachment,
                metadata: [
                    'attachment_id' => $attachmentId,
                    'independent_public_derivative_count' => $derivativeCount,
                ],
            );

            $attachment->forceDelete();

            $this->storedFileCleanup->schedule($disk, $path, 'private_attachment_deleted');
        });
    }
}
