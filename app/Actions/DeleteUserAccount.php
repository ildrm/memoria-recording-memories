<?php

namespace App\Actions;

use App\Enums\PublicationTargetStatus;
use App\Enums\SocialPostStatus;
use App\Events\AccountDeleted;
use App\Jobs\DispatchPendingRemoteSocialPostDeletions;
use App\Jobs\DispatchPendingStoredFileDeletions;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\Report;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditRecorder;
use App\Services\Social\RemoteSocialPostCleanup;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteUserAccount
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
        private readonly RemoteSocialPostCleanup $remoteSocialPostCleanup,
    ) {}

    public function handle(User $user): void
    {
        Gate::forUser($user)->authorize('update', $user);

        $formerUserId = (int) $user->getKey();

        DB::transaction(function () use ($user, $formerUserId): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $email = $user->email;
            $user->forceFill(['account_deletion_requested_at' => now()])->save();
            $filesScheduledForRemoval = $this->scheduleOwnedFiles($user);

            SocialPost::query()
                ->ownedBy($user)
                ->where('status', SocialPostStatus::Published)
                ->whereNotNull('remote_post_id')
                ->lazyById()
                ->each(fn (SocialPost $socialPost) => $this->remoteSocialPostCleanup->schedule(
                    $socialPost,
                    'account_deleted',
                    dispatchImmediately: false,
                ));

            $user->socialAccounts()->update([
                'access_token' => encrypt(''),
                'refresh_token' => null,
                'revoked_at' => now(),
            ]);
            $user->reminders()->update(['is_enabled' => false, 'next_run_at' => null]);
            Publication::query()->ownedBy($user)->lazyById()->each(function (Publication $publication): void {
                $publication->targets()->whereIn('status', [
                    PublicationTargetStatus::Pending,
                    PublicationTargetStatus::Scheduled,
                    PublicationTargetStatus::Processing,
                ])->update(['status' => PublicationTargetStatus::Cancelled->value]);
                $publication->socialPosts()->whereIn('status', [
                    SocialPostStatus::Pending,
                    SocialPostStatus::Scheduled,
                    SocialPostStatus::Processing,
                    SocialPostStatus::Retrying,
                ])->update(['status' => SocialPostStatus::Cancelled->value]);
                $publication->shareLinks()->update(['revoked_at' => now()]);
            });
            Entry::query()->ownedBy($user)->lazyById()->each(
                fn (Entry $entry) => $entry->shareLinks()->update(['revoked_at' => now()]),
            );

            $this->auditRecorder->record(
                event: 'account.deleted',
                actor: $user,
                auditable: $user,
                metadata: ['files_scheduled_for_removal' => $filesScheduledForRemoval],
            );

            Comment::query()->whereBelongsTo($user, 'author')->forceDelete();
            Report::query()->whereBelongsTo($user, 'reporter')->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('sessions')->where('user_id', $formerUserId)->delete();
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $formerUserId)
                ->delete();

            $user->delete();
            AccountDeleted::dispatch($formerUserId);
        });

        DispatchPendingStoredFileDeletions::dispatch()->afterCommit();
        DispatchPendingRemoteSocialPostDeletions::dispatch()->afterCommit();
    }

    private function scheduleOwnedFiles(User $user): int
    {
        $scheduled = 0;

        Attachment::withTrashed()->ownedBy($user)->select(['id', 'disk', 'path'])->lazyById()
            ->each(function (Attachment $attachment) use (&$scheduled): void {
                $this->scheduleOwnedFile($attachment->disk, $attachment->path, $scheduled);
            });
        PublicationMedia::query()->ownedBy($user)->select([
            'id', 'disk', 'path', 'mime_type', 'size_bytes', 'metadata_stripped', 'metadata',
        ])->lazyById()
            ->each(function (PublicationMedia $medium) use (&$scheduled): void {
                foreach ($medium->storedImageFiles() as $file) {
                    $this->scheduleOwnedFile($file['disk'], $file['path'], $scheduled);
                }
            });
        Export::query()->ownedBy($user)->whereNotNull('path')->select(['id', 'disk', 'path'])->lazyById()
            ->each(function (Export $export) use (&$scheduled): void {
                if ($export->disk !== null && $export->path !== null) {
                    $this->scheduleOwnedFile($export->disk, $export->path, $scheduled);
                }
            });
        Journal::withTrashed()->ownedBy($user)->whereNotNull('cover_path')->select(['id', 'cover_path'])->lazyById()
            ->each(function (Journal $journal) use (&$scheduled): void {
                $this->scheduleOwnedFile(
                    (string) config('memoria.disks.private', 'local'),
                    (string) $journal->cover_path,
                    $scheduled,
                );
            });
        Person::withTrashed()->ownedBy($user)->whereNotNull('avatar_path')->select(['id', 'avatar_path'])->lazyById()
            ->each(function (Person $person) use (&$scheduled): void {
                $this->scheduleOwnedFile(
                    (string) config('memoria.disks.private', 'local'),
                    (string) $person->avatar_path,
                    $scheduled,
                );
            });

        $profile = UserProfile::query()->whereBelongsTo($user)->first();
        foreach ([
            [$profile?->avatar_path, $profile?->avatar_disk],
            [$profile?->cover_image_path, $profile?->cover_image_disk],
        ] as [$path, $disk]) {
            if (is_string($path) && $path !== '') {
                $this->scheduleOwnedFile(
                    is_string($disk) && $disk !== ''
                        ? $disk
                        : (string) config('memoria.disks.sanitized_media', 'local'),
                    $path,
                    $scheduled,
                );
            }
        }

        return $scheduled;
    }

    private function scheduleOwnedFile(string $disk, string $path, int &$scheduled): void
    {
        $this->storedFileCleanup->schedule(
            $disk,
            $path,
            'account_deleted',
            dispatchImmediately: false,
        );
        $scheduled++;
    }
}
