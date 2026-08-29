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
use Illuminate\Validation\ValidationException;

class UnpublishPublication
{
    public function __construct(
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly RemoteSocialPostCleanup $remoteSocialPostCleanup,
    ) {}

    public function handle(Publication $publication, User $owner): Publication
    {
        Gate::forUser($owner)->authorize('publish', $publication);

        return DB::transaction(function () use ($publication, $owner): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            if ($publication->status !== PublicationStatus::Published) {
                throw ValidationException::withMessages([
                    'status' => [__('Only a published publication can be unpublished.')],
                ]);
            }

            $publication->socialPosts()
                ->where('status', SocialPostStatus::Published)
                ->whereNotNull('remote_post_id')
                ->lazyById()
                ->each(fn (SocialPost $socialPost) => $this->remoteSocialPostCleanup->schedule(
                    $socialPost,
                    'publication_unpublished',
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

            $this->snapshotter->snapshot($publication, 'unpublished');
            $this->auditRecorder->record(
                event: 'publication.unpublished',
                actor: $owner,
                auditable: $publication,
                metadata: ['external_copies_may_remain' => true],
            );

            PublicationUnpublished::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
            );

            return $publication->refresh();
        });
    }
}
