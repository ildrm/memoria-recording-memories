<?php

namespace App\Services;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialPostStatus;
use App\Models\Publication;
use App\Models\SocialPost;
use App\Services\Social\RemoteSocialPostCleanup;
use Illuminate\Validation\ValidationException;

class PublicationEditTransition
{
    public function __construct(
        private readonly RemoteSocialPostCleanup $remoteSocialPostCleanup,
    ) {}

    /**
     * Apply this only to a publication already locked for update.
     *
     * @return array{previous_status: PublicationStatus, visibility_withdrawn: bool}
     */
    public function apply(Publication $publication, string $remoteDeletionReason): array
    {
        $previousStatus = PublicationStatus::from(
            (string) $publication->getRawOriginal('status'),
        );

        if ($previousStatus === PublicationStatus::Archived) {
            throw ValidationException::withMessages([
                'status' => [__('Archived publications cannot be edited.')],
            ]);
        }

        $visibilityWithdrawn = in_array($previousStatus, [
            PublicationStatus::Published,
            PublicationStatus::Scheduled,
        ], true);

        if ($visibilityWithdrawn) {
            if ($previousStatus === PublicationStatus::Published) {
                $publication->socialPosts()
                    ->where('status', SocialPostStatus::Published)
                    ->whereNotNull('remote_post_id')
                    ->lazyById()
                    ->each(fn (SocialPost $socialPost) => $this->remoteSocialPostCleanup->schedule(
                        $socialPost,
                        $remoteDeletionReason,
                    ));
            }

            $this->cancelActiveDelivery($publication);
            $status = $previousStatus === PublicationStatus::Published
                ? PublicationStatus::Unpublished
                : PublicationStatus::Draft;
            $publication->forceFill([
                'status' => $status,
                'scheduled_at' => null,
                'unpublished_at' => $previousStatus === PublicationStatus::Published
                    ? now()
                    : $publication->unpublished_at,
            ]);
        }

        $publication->privacy_reviewed_at = null;

        return [
            'previous_status' => $previousStatus,
            'visibility_withdrawn' => $visibilityWithdrawn,
        ];
    }

    private function cancelActiveDelivery(Publication $publication): void
    {
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
    }
}
