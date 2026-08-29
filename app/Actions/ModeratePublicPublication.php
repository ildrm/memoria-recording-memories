<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialPostStatus;
use App\Events\PublicationUnpublished;
use App\Models\Publication;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationSnapshotter;
use App\Services\Social\RemoteSocialPostCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ModeratePublicPublication
{
    public function __construct(
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly RemoteSocialPostCleanup $remoteSocialPostCleanup,
    ) {}

    public function handle(
        Publication $publication,
        User $moderator,
        ?string $reason = null,
    ): Publication {
        Gate::forUser($moderator)->authorize('moderate', $publication);

        return DB::transaction(function () use ($publication, $moderator, $reason): Publication {
            $publication = Publication::query()
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            Gate::forUser($moderator)->authorize('moderate', $publication);

            if ($publication->status !== PublicationStatus::Published) {
                throw ValidationException::withMessages([
                    'status' => [__('Only a published publication can be moderated from public view.')],
                ]);
            }

            $publication->socialPosts()
                ->where('status', SocialPostStatus::Published)
                ->whereNotNull('remote_post_id')
                ->lazyById()
                ->each(fn (SocialPost $socialPost) => $this->remoteSocialPostCleanup->schedule(
                    $socialPost,
                    'publication_moderated',
                ));

            $publication->targets()
                ->where('type', PublicationTargetType::Website)
                ->update([
                    'status' => PublicationTargetStatus::Cancelled->value,
                    'scheduled_at' => null,
                ]);

            $publication->targets()
                ->where('type', PublicationTargetType::Social)
                ->whereIn('status', [
                    PublicationTargetStatus::Pending,
                    PublicationTargetStatus::Scheduled,
                    PublicationTargetStatus::Processing,
                    PublicationTargetStatus::Failed,
                ])
                ->update([
                    'status' => PublicationTargetStatus::Cancelled->value,
                    'scheduled_at' => null,
                ]);

            $publication->socialPosts()
                ->whereIn('status', [
                    SocialPostStatus::Pending,
                    SocialPostStatus::Scheduled,
                    SocialPostStatus::Processing,
                    SocialPostStatus::Retrying,
                ])
                ->update([
                    'status' => SocialPostStatus::Cancelled->value,
                    'next_retry_at' => null,
                ]);

            $publication->forceFill([
                'status' => PublicationStatus::Unpublished,
                'scheduled_at' => null,
                'unpublished_at' => now(),
            ])->save();

            $this->snapshotter->snapshot($publication, 'moderated_unpublished');
            $this->auditRecorder->record(
                event: 'publication.moderated_unpublished',
                actor: $moderator,
                auditable: $publication,
                metadata: [
                    'reason' => filled($reason) ? Str::limit(trim((string) $reason), 500, '') : null,
                    'owner_user_id' => $publication->user_id,
                    'external_copies_may_remain' => true,
                ],
            );

            PublicationUnpublished::dispatch(
                (int) $publication->getKey(),
                (int) $publication->user_id,
            );

            return $publication->refresh();
        });
    }
}
