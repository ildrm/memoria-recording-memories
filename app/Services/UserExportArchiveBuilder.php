<?php

namespace App\Services;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\EntryVersion;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationTarget;
use App\Models\PublicationVersion;
use App\Models\Reaction;
use App\Models\Reminder;
use App\Models\Report;
use App\Models\ShareLink;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class UserExportArchiveBuilder
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    /**
     * @return array{disk: string, path: string, filename: string, size: int}
     */
    public function build(Export $export, User $owner): array
    {
        $workingDirectory = storage_path('app/private/export-work');
        $this->files->ensureDirectoryExists($workingDirectory);
        $temporaryArchive = tempnam($workingDirectory, 'memoria-export-');

        if ($temporaryArchive === false) {
            throw new RuntimeException('Unable to allocate export workspace.');
        }

        /** @var array<int, string> $temporaryFiles */
        $temporaryFiles = [];
        /** @var array{disk: string, path: string}|null $storedArchive */
        $storedArchive = null;
        $archiveStream = null;
        $zip = new ZipArchive;
        $archiveIsOpen = false;

        try {
            if ($zip->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create export archive.');
            }
            $archiveIsOpen = true;

            $options = $export->options ?? [];
            $formats = array_intersect((array) ($options['formats'] ?? ['json', 'markdown']), ['json', 'markdown']);
            $includeAttachments = (bool) ($options['include_attachments'] ?? true);
            $fileCounts = $includeAttachments
                ? [
                    'attachments' => $this->addAttachments($zip, $owner, $workingDirectory, $temporaryFiles),
                    'publication_media_files' => $this->addPublicationMediaFiles($zip, $owner, $workingDirectory, $temporaryFiles),
                    'profile_images' => $this->addProfileImages($zip, $owner, $workingDirectory, $temporaryFiles),
                    'journal_images' => $this->addJournalImages($zip, $owner, $workingDirectory, $temporaryFiles),
                    'person_images' => $this->addPersonImages($zip, $owner, $workingDirectory, $temporaryFiles),
                ]
                : [
                    'attachments' => 0,
                    'publication_media_files' => 0,
                    'profile_images' => 0,
                    'journal_images' => 0,
                    'person_images' => 0,
                ];

            $counts = [
                'journals' => $this->addJournals($zip, $owner, $workingDirectory, $temporaryFiles),
                'entries' => $this->addEntries($zip, $owner, $formats),
                'entry_versions' => $this->addEntryVersions($zip, $owner, $workingDirectory, $temporaryFiles),
                'tags' => $this->addTags($zip, $owner, $workingDirectory, $temporaryFiles),
                'people' => $this->addPeople($zip, $owner, $workingDirectory, $temporaryFiles),
                'publications' => $this->addPublications($zip, $owner),
                'attachment_records' => $this->addAttachmentMetadata($zip, $owner, $workingDirectory, $temporaryFiles),
                'publication_media_records' => $this->addPublicationMediaMetadata($zip, $owner, $workingDirectory, $temporaryFiles),
                'publication_versions' => $this->addPublicationVersions($zip, $owner, $workingDirectory, $temporaryFiles),
                'shares' => $this->addShares($zip, $owner, $workingDirectory, $temporaryFiles),
                'reminders' => $this->addReminders($zip, $owner, $workingDirectory, $temporaryFiles),
                'community' => $this->addCommunityActivity($zip, $owner, $workingDirectory, $temporaryFiles),
                'social' => $this->addSocialMetadata($zip, $owner, $workingDirectory, $temporaryFiles),
                ...$fileCounts,
            ];

            $owner->loadMissing(['profile', 'preferences']);
            $this->addString($zip, 'metadata/account.json', $this->json([
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'email' => $owner->email,
                'profile' => $owner->profile === null ? null : array_merge(
                    $owner->profile->only([
                        'username',
                        'display_name',
                        'biography',
                        'website_url',
                        'is_public',
                    ]),
                    [
                        'has_avatar' => filled($owner->profile->avatar_path),
                        'has_cover_image' => filled($owner->profile->cover_image_path),
                    ],
                ),
                'preferences' => $owner->preferences?->only([
                    'locale',
                    'timezone',
                    'appearance',
                    'on_this_day_enabled',
                    'notification_preferences',
                    'privacy_preferences',
                ]),
            ]));
            $this->addString($zip, 'manifest.json', $this->json([
                'schema' => 'memoria-export-v2',
                'exported_at' => now()->toIso8601String(),
                'formats' => array_values($formats),
                'includes_original_attachments' => $includeAttachments,
                'includes_sanitized_public_media' => $includeAttachments,
                'includes_profile_and_collection_images' => $includeAttachments,
                'counts' => $counts,
            ]));
            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize export archive.');
            }
            $archiveIsOpen = false;

            $disk = (string) config('memoria.disks.exports', 'local');
            $directory = trim((string) config('memoria.exports.directory', 'exports'), '/');
            $filename = 'memoria-export-'.now()->format('Y-m-d').'-'.Str::lower(Str::random(8)).'.zip';
            $path = $directory.'/'.$owner->getKey().'/'.$export->getKey().'/'.$filename;
            $expectedArchiveSize = filesize($temporaryArchive);
            if (! is_int($expectedArchiveSize) || $expectedArchiveSize < 1) {
                throw new RuntimeException('Unable to verify export archive.');
            }

            $storedArchive = ['disk' => $disk, 'path' => $path];
            $archiveStream = fopen($temporaryArchive, 'rb');

            if ($archiveStream === false || ! Storage::disk($disk)->writeStream($path, $archiveStream)) {
                throw new RuntimeException('Unable to store export archive.');
            }

            fclose($archiveStream);
            $archiveStream = null;
            $storedArchiveSize = (int) Storage::disk($disk)->size($path);
            if ($storedArchiveSize !== $expectedArchiveSize) {
                throw new RuntimeException('The stored export archive failed verification.');
            }

            return [
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename,
                'size' => $storedArchiveSize,
            ];
        } catch (Throwable $exception) {
            if ($archiveIsOpen) {
                $zip->close();
            }

            if ($storedArchive !== null) {
                $this->removeFailedStoredArchive($storedArchive, $exception);
            }

            throw $exception;
        } finally {
            if (is_resource($archiveStream)) {
                fclose($archiveStream);
            }

            foreach ($temporaryFiles as $temporaryPath) {
                $this->files->delete($temporaryPath);
            }

            $this->files->delete($temporaryArchive);
        }
    }

    /**
     * @param  array{disk: string, path: string}  $storedArchive
     */
    private function removeFailedStoredArchive(array $storedArchive, Throwable $exportException): void
    {
        $deletionScheduled = false;
        $deleted = false;

        try {
            $this->storedFileCleanup->schedule(
                $storedArchive['disk'],
                $storedArchive['path'],
                'failed_user_export_archive',
                dispatchImmediately: false,
            );
            $deletionScheduled = true;
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }

        try {
            $deleted = Storage::disk($storedArchive['disk'])->delete($storedArchive['path']);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }

        if (! $deletionScheduled && ! $deleted) {
            throw new RuntimeException(
                'A failed export archive could not be removed or scheduled for removal.',
                previous: $exportException,
            );
        }
    }

    /** @param array<int, string> $temporaryFiles */
    private function addJournals(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $journals = Journal::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Journal $journal): array => array_merge(
                $journal->only([
                    'id', 'name', 'slug', 'description', 'icon', 'sort_order', 'archived_at',
                    'created_at', 'updated_at', 'deleted_at',
                ]),
                ['has_cover_image' => filled($journal->cover_path)],
            ));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/journals.json',
            $workingDirectory,
            $temporaryFiles,
            $journals,
        );
    }

    /**
     * @param  array<int, string>  $formats
     */
    private function addEntries(ZipArchive $zip, User $owner, array $formats): int
    {
        $count = 0;

        Entry::withTrashed()
            ->ownedBy($owner)
            ->with(['tags:id,name,color', 'people:id,display_name,nickname,relationship'])
            ->orderBy('id')
            ->chunkById(
                $this->chunkSize(),
                function ($entries) use ($zip, $formats, &$count): void {
                    foreach ($entries as $entry) {
                        $payload = array_merge($entry->only([
                            'id', 'journal_id', 'title', 'body', 'occurred_at', 'timezone',
                            'mood', 'custom_mood', 'location_name', 'latitude', 'longitude',
                            'importance', 'status', 'is_favorite', 'archived_at', 'revision',
                            'created_at', 'updated_at', 'deleted_at',
                        ]), [
                            'tags' => $entry->tags->map->only(['id', 'name', 'color'])->all(),
                            'people' => $entry->people->map->only([
                                'id', 'display_name', 'nickname', 'relationship',
                            ])->all(),
                        ]);

                        if (in_array('json', $formats, true)) {
                            $this->addString($zip, "entries/{$entry->getKey()}.json", $this->json($payload));
                        }

                        if (in_array('markdown', $formats, true)) {
                            $this->addString(
                                $zip,
                                "entries/{$entry->getKey()}.md",
                                '# '.($entry->title ?: __('Untitled memory'))."\n\n".(string) $entry->body,
                            );
                        }

                        $count++;
                    }
                },
                'id',
            );

        return $count;
    }

    private function addPublications(ZipArchive $zip, User $owner): int
    {
        $count = 0;

        Publication::withTrashed()
            ->ownedBy($owner)
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($publications) use ($zip, &$count): void {
                foreach ($publications as $publication) {
                    $this->addString(
                        $zip,
                        "publications/{$publication->getKey()}.json",
                        $this->json($publication->only([
                            'id', 'source_entry_id', 'title', 'slug', 'excerpt', 'body',
                            'topics',
                            'status', 'comments_enabled', 'reactions_enabled',
                            'search_engine_indexing', 'scheduled_at', 'published_at',
                            'unpublished_at', 'archived_at', 'revision', 'created_at',
                            'updated_at', 'deleted_at',
                        ])),
                    );
                    $count++;
                }
            }, 'id');

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addAttachmentMetadata(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $attachments = Attachment::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Attachment $attachment): array => $attachment->only([
                'id', 'entry_id', 'original_name', 'download_name', 'mime_type',
                'extension', 'size_bytes', 'media_type', 'sha256', 'scan_status',
                'scanned_at', 'metadata', 'created_at', 'updated_at', 'deleted_at',
            ]));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/attachments.json',
            $workingDirectory,
            $temporaryFiles,
            $attachments,
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addPublicationMediaMetadata(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $media = PublicationMedia::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (PublicationMedia $medium): array => [
                ...$medium->only([
                    'id', 'publication_id', 'source_attachment_id', 'original_name',
                    'mime_type', 'size_bytes', 'alt_text', 'sort_order', 'is_featured',
                    'metadata_stripped', 'created_at', 'updated_at',
                ]),
                'image' => [
                    'width' => (int) data_get($medium->metadata, 'width', 0),
                    'height' => (int) data_get($medium->metadata, 'height', 0),
                    'variants' => collect($medium->responsiveImageVariants())
                        ->map(fn (array $variant): array => Arr::only($variant, [
                            'name', 'mime_type', 'size_bytes', 'width', 'height',
                        ]))
                        ->values()
                        ->all(),
                ],
            ]);

        return $this->addJsonArrayFile(
            $zip,
            'metadata/publication-media.json',
            $workingDirectory,
            $temporaryFiles,
            $media,
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addEntryVersions(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $versions = EntryVersion::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (EntryVersion $version): array => $version->only([
                'id', 'entry_id', 'version', 'title', 'body', 'occurred_at', 'timezone',
                'mood', 'custom_mood', 'location_name', 'latitude', 'longitude',
                'importance', 'reason', 'created_at', 'updated_at',
            ]));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/entry-versions.json',
            $workingDirectory,
            $temporaryFiles,
            $versions,
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addPublicationVersions(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $versions = PublicationVersion::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (PublicationVersion $version): array => $version->only([
                'id', 'publication_id', 'version', 'title', 'excerpt', 'body', 'status',
                'settings', 'reason', 'created_at', 'updated_at',
            ]));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/publication-versions.json',
            $workingDirectory,
            $temporaryFiles,
            $versions,
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addTags(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $tags = Tag::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Tag $tag): array => $tag->only([
                'id', 'name', 'color', 'created_at', 'updated_at',
            ]));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/tags.json',
            $workingDirectory,
            $temporaryFiles,
            $tags,
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addPeople(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $people = Person::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Person $person): array => array_merge(
                $person->only([
                    'id', 'display_name', 'nickname', 'notes', 'relationship',
                    'created_at', 'updated_at', 'deleted_at',
                ]),
                ['has_avatar' => filled($person->avatar_path)],
            ));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/people.json',
            $workingDirectory,
            $temporaryFiles,
            $people,
        );
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     * @return array{entry_shares_sent: int, entry_shares_received: int, share_links: int}
     */
    private function addShares(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): array {
        $sent = EntryShare::query()
            ->where('shared_by_user_id', $owner->getKey())
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (EntryShare $share): array => $share->only([
                'id', 'entry_id', 'shared_with_user_id', 'permission', 'include_attachments',
                'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ]));
        $received = EntryShare::query()
            ->where('shared_with_user_id', $owner->getKey())
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (EntryShare $share): array => $share->only([
                'id', 'entry_id', 'shared_by_user_id', 'permission', 'include_attachments',
                'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ]));
        $links = ShareLink::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (ShareLink $link): array => $link->only([
                'id', 'entry_id', 'publication_id', 'label', 'include_attachments',
                'track_views', 'view_count', 'max_views', 'last_accessed_at',
                'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ]));

        return $this->addJsonObjectFile(
            $zip,
            'metadata/shares.json',
            $workingDirectory,
            $temporaryFiles,
            [
                'entry_shares_sent' => $sent,
                'entry_shares_received' => $received,
                'share_links' => $links,
            ],
        );
    }

    /** @param array<int, string> $temporaryFiles */
    private function addReminders(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $reminders = Reminder::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Reminder $reminder): array => $reminder->only([
                'id', 'name', 'frequency', 'local_time', 'day_of_week', 'day_of_month',
                'interval_days', 'timezone', 'channels', 'is_enabled', 'next_run_at',
                'last_sent_at', 'created_at', 'updated_at',
            ]));

        return $this->addJsonArrayFile(
            $zip,
            'metadata/reminders.json',
            $workingDirectory,
            $temporaryFiles,
            $reminders,
        );
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     * @return array{comments: int, reactions: int, reports: int}
     */
    private function addCommunityActivity(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): array {
        $comments = Comment::withTrashed()
            ->where('user_id', $owner->getKey())
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Comment $comment): array => $comment->only([
                'id', 'publication_id', 'parent_id', 'body', 'status', 'moderated_at',
                'created_at', 'updated_at', 'deleted_at',
            ]));
        $reactions = Reaction::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Reaction $reaction): array => $reaction->only([
                'id', 'publication_id', 'type', 'created_at', 'updated_at',
            ]));
        $reports = Report::query()
            ->where('reporter_user_id', $owner->getKey())
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Report $report): array => $report->only([
                'id', 'publication_id', 'comment_id', 'reason', 'details', 'status',
                'resolution', 'resolved_at', 'created_at', 'updated_at',
            ]));

        return $this->addJsonObjectFile(
            $zip,
            'metadata/community.json',
            $workingDirectory,
            $temporaryFiles,
            [
                'comments' => $comments,
                'reactions' => $reactions,
                'reports' => $reports,
            ],
        );
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     * @return array{accounts: int, publication_targets: int, posts: int, failures: int}
     */
    private function addSocialMetadata(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): array {
        $accounts = SocialAccount::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (SocialAccount $account): array => array_merge(
                $account->only([
                    'id', 'provider', 'provider_user_id', 'username', 'display_name', 'server_url',
                    'token_expires_at', 'scopes', 'connected_at', 'last_refreshed_at',
                    'revoked_at', 'created_at', 'updated_at', 'deleted_at',
                ]),
                ['configuration' => Arr::only(
                    is_array($account->metadata) ? $account->metadata : [],
                    ['page_id', 'page_name'],
                )],
            ));
        $targets = PublicationTarget::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (PublicationTarget $target): array => $target->only([
                'id', 'publication_id', 'social_account_id', 'target_key', 'type', 'provider',
                'status', 'content_override', 'scheduled_at', 'dispatched_at', 'completed_at',
                'failed_at', 'created_at', 'updated_at',
            ]));
        $posts = SocialPost::query()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (SocialPost $post): array => $post->only([
                'id', 'publication_id', 'publication_target_id', 'social_account_id',
                'provider', 'status', 'content', 'remote_post_id', 'remote_url',
                'attempt_count', 'scheduled_at', 'last_attempted_at', 'next_retry_at',
                'published_at', 'failed_at', 'error_code', 'error_message',
                'created_at', 'updated_at',
            ]));
        $failures = SocialPostFailure::query()
            ->whereIn(
                'social_post_id',
                SocialPost::query()->ownedBy($owner)->select('id'),
            )
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (SocialPostFailure $failure): array => $failure->only([
                'id', 'social_post_id', 'attempt', 'error_code', 'is_retryable',
                'occurred_at', 'created_at', 'updated_at',
            ]));

        return $this->addJsonObjectFile(
            $zip,
            'metadata/social.json',
            $workingDirectory,
            $temporaryFiles,
            [
                'accounts' => $accounts,
                'publication_targets' => $targets,
                'posts' => $posts,
                'failures' => $failures,
            ],
        );
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     * @param  iterable<array-key, array<string, mixed>>  $records
     */
    private function addJsonArrayFile(
        ZipArchive $zip,
        string $archivePath,
        string $workingDirectory,
        array &$temporaryFiles,
        iterable $records,
    ): int {
        $temporaryPath = $this->temporaryFile($workingDirectory, $temporaryFiles, 'metadata-');
        $stream = fopen($temporaryPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to write export metadata workspace.');
        }

        try {
            $count = $this->writeJsonArray($stream, $records);
        } finally {
            fclose($stream);
        }

        if (! $zip->addFile($temporaryPath, $archivePath)) {
            throw new RuntimeException('Unable to add export metadata to the archive.');
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     * @param  array<string, iterable<array-key, array<string, mixed>>>  $collections
     * @return array<string, int>
     */
    private function addJsonObjectFile(
        ZipArchive $zip,
        string $archivePath,
        string $workingDirectory,
        array &$temporaryFiles,
        array $collections,
    ): array {
        $temporaryPath = $this->temporaryFile($workingDirectory, $temporaryFiles, 'metadata-');
        $stream = fopen($temporaryPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to write export metadata workspace.');
        }

        $counts = [];
        $firstProperty = true;

        try {
            $this->writeToStream($stream, '{');

            foreach ($collections as $name => $records) {
                $this->writeToStream(
                    $stream,
                    ($firstProperty ? "\n" : ",\n")
                        .'    '.$this->json($name).': ',
                );
                $counts[$name] = $this->writeJsonArray($stream, $records, 1);
                $firstProperty = false;
            }

            if (! $firstProperty) {
                $this->writeToStream($stream, "\n");
            }

            $this->writeToStream($stream, '}');
        } finally {
            fclose($stream);
        }

        if (! $zip->addFile($temporaryPath, $archivePath)) {
            throw new RuntimeException('Unable to add export metadata to the archive.');
        }

        return $counts;
    }

    /**
     * @param  resource  $stream
     * @param  iterable<array-key, array<string, mixed>>  $records
     */
    private function writeJsonArray(mixed $stream, iterable $records, int $indentLevel = 0): int
    {
        $count = 0;
        $closingIndent = str_repeat(' ', $indentLevel * 4);
        $itemIndent = str_repeat(' ', ($indentLevel + 1) * 4);

        $this->writeToStream($stream, '[');

        foreach ($records as $record) {
            $encoded = str_replace("\n", "\n{$itemIndent}", $this->json($record));
            $this->writeToStream(
                $stream,
                ($count === 0 ? "\n" : ",\n").$itemIndent.$encoded,
            );
            $count++;
        }

        if ($count > 0) {
            $this->writeToStream($stream, "\n{$closingIndent}");
        }

        $this->writeToStream($stream, ']');

        return $count;
    }

    /** @param resource $stream */
    private function writeToStream(mixed $stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('Unable to stream export metadata.');
            }

            $offset += $written;
        }
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     */
    private function temporaryFile(
        string $workingDirectory,
        array &$temporaryFiles,
        string $prefix,
    ): string {
        $temporaryPath = tempnam($workingDirectory, $prefix);
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate export metadata workspace.');
        }

        $temporaryFiles[] = $temporaryPath;

        return $temporaryPath;
    }

    private function chunkSize(): int
    {
        return max(10, (int) config('memoria.exports.chunk_size', 100));
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     */
    private function addAttachments(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $count = 0;

        Attachment::query()
            ->ownedBy($owner)
            ->where('scan_status', AttachmentScanStatus::Clean)
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($attachments) use (
                $zip,
                $workingDirectory,
                &$temporaryFiles,
                &$count,
            ): void {
                foreach ($attachments as $attachment) {
                    $safeName = Str::slug(pathinfo($attachment->original_name, PATHINFO_FILENAME)) ?: 'attachment';
                    $extension = $this->safeExtension(
                        (string) $attachment->path,
                        (string) $attachment->mime_type,
                        (string) $attachment->extension,
                    );
                    $archiveName = "media/{$attachment->entry_id}/{$attachment->getKey()}-{$safeName}";
                    if ($extension !== '') {
                        $archiveName .= '.'.Str::lower($extension);
                    }

                    $this->addStoredFile(
                        zip: $zip,
                        disk: (string) $attachment->disk,
                        path: (string) $attachment->path,
                        archiveName: $archiveName,
                        workingDirectory: $workingDirectory,
                        temporaryFiles: $temporaryFiles,
                        expectedSize: (int) $attachment->size_bytes,
                        expectedSha256: (string) $attachment->sha256,
                    );
                    $count++;
                }
            }, 'id');

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addPublicationMediaFiles(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $count = 0;

        PublicationMedia::query()
            ->ownedBy($owner)
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($media) use (
                $zip,
                $workingDirectory,
                &$temporaryFiles,
                &$count,
            ): void {
                foreach ($media as $medium) {
                    $variantsByPath = collect($medium->responsiveImageVariants())->keyBy('path');

                    foreach ($medium->storedImageFiles() as $fileIndex => $file) {
                        $variant = $variantsByPath->get($file['path']);
                        $variantName = is_array($variant)
                            ? (string) $variant['name']
                            : ($file['path'] === $medium->path ? 'original' : 'variant-'.$fileIndex);
                        $mimeType = is_array($variant)
                            ? (string) $variant['mime_type']
                            : (string) $medium->mime_type;
                        $expectedSize = is_array($variant)
                            ? (int) $variant['size_bytes']
                            : ($file['path'] === $medium->path ? (int) $medium->size_bytes : null);
                        $extension = $this->safeExtension($file['path'], $mimeType);
                        $safeVariantName = preg_replace('/[^a-z0-9-]/i', '', $variantName) ?: 'image';
                        $archiveName = "media/publications/{$medium->publication_id}/{$medium->getKey()}-{$safeVariantName}";
                        if ($extension !== '') {
                            $archiveName .= '.'.$extension;
                        }

                        $this->addStoredFile(
                            zip: $zip,
                            disk: $file['disk'],
                            path: $file['path'],
                            archiveName: $archiveName,
                            workingDirectory: $workingDirectory,
                            temporaryFiles: $temporaryFiles,
                            expectedSize: $expectedSize,
                        );
                        $count++;
                    }
                }
            }, 'id');

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addProfileImages(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $owner->loadMissing('profile');
        $profile = $owner->profile;
        if ($profile === null) {
            return 0;
        }

        $count = 0;
        foreach ([
            'avatar' => [$profile->avatar_path, $profile->avatar_disk],
            'cover' => [$profile->cover_image_path, $profile->cover_image_disk],
        ] as $kind => [$path, $disk]) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $extension = $this->safeExtension($path);
            $this->addStoredFile(
                zip: $zip,
                disk: is_string($disk) && $disk !== ''
                    ? $disk
                    : (string) config('memoria.disks.sanitized_media', 'local'),
                path: $path,
                archiveName: "media/profile/{$kind}".($extension === '' ? '' : '.'.$extension),
                workingDirectory: $workingDirectory,
                temporaryFiles: $temporaryFiles,
            );
            $count++;
        }

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addJournalImages(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $count = 0;

        Journal::query()
            ->ownedBy($owner)
            ->whereNotNull('cover_path')
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($journals) use (
                $zip,
                $workingDirectory,
                &$temporaryFiles,
                &$count,
            ): void {
                foreach ($journals as $journal) {
                    $path = (string) $journal->cover_path;
                    $extension = $this->safeExtension($path);
                    $this->addStoredFile(
                        zip: $zip,
                        disk: (string) config('memoria.disks.private', 'local'),
                        path: $path,
                        archiveName: "media/journals/{$journal->getKey()}-cover".($extension === '' ? '' : '.'.$extension),
                        workingDirectory: $workingDirectory,
                        temporaryFiles: $temporaryFiles,
                    );
                    $count++;
                }
            }, 'id');

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addPersonImages(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int {
        $count = 0;

        Person::query()
            ->ownedBy($owner)
            ->whereNotNull('avatar_path')
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($people) use (
                $zip,
                $workingDirectory,
                &$temporaryFiles,
                &$count,
            ): void {
                foreach ($people as $person) {
                    $path = (string) $person->avatar_path;
                    $extension = $this->safeExtension($path);
                    $this->addStoredFile(
                        zip: $zip,
                        disk: (string) config('memoria.disks.private', 'local'),
                        path: $path,
                        archiveName: "media/people/{$person->getKey()}-avatar".($extension === '' ? '' : '.'.$extension),
                        workingDirectory: $workingDirectory,
                        temporaryFiles: $temporaryFiles,
                    );
                    $count++;
                }
            }, 'id');

        return $count;
    }

    /** @param array<int, string> $temporaryFiles */
    private function addStoredFile(
        ZipArchive $zip,
        string $disk,
        string $path,
        string $archiveName,
        string $workingDirectory,
        array &$temporaryFiles,
        ?int $expectedSize = null,
        ?string $expectedSha256 = null,
    ): void {
        if ($disk === '' || ! $this->storedPathIsSafe($path)) {
            throw new RuntimeException('An export media record has an invalid storage target.');
        }

        try {
            $source = Storage::disk($disk)->readStream($path);
        } catch (Throwable) {
            throw new RuntimeException('An expected export media object could not be read.');
        }

        if (! is_resource($source)) {
            throw new RuntimeException('An expected export media object is missing.');
        }

        $temporaryPath = $this->temporaryFile($workingDirectory, $temporaryFiles, 'media-');
        $destination = fopen($temporaryPath, 'wb');
        if ($destination === false) {
            fclose($source);

            throw new RuntimeException('Unable to write media export workspace.');
        }

        try {
            $copiedBytes = stream_copy_to_stream($source, $destination);
        } finally {
            fclose($source);
            fclose($destination);
        }

        if (! is_int($copiedBytes)
            || ($expectedSize !== null && $expectedSize > 0 && $copiedBytes !== $expectedSize)) {
            throw new RuntimeException('An export media object failed size verification.');
        }

        if (is_string($expectedSha256) && $expectedSha256 !== '') {
            $actualSha256 = hash_file('sha256', $temporaryPath);
            if (! is_string($actualSha256) || ! hash_equals($expectedSha256, $actualSha256)) {
                throw new RuntimeException('An export media object failed integrity verification.');
            }
        }

        if (! $zip->addFile($temporaryPath, $archiveName)) {
            throw new RuntimeException('Unable to add media to the export archive.');
        }
    }

    private function safeExtension(string $path, ?string $mimeType = null, ?string $preferred = null): string
    {
        $mimeExtension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            default => null,
        };
        $candidate = $mimeExtension ?? $preferred ?? pathinfo($path, PATHINFO_EXTENSION);

        return Str::lower(preg_replace('/[^a-z0-9]/i', '', (string) $candidate) ?? '');
    }

    private function storedPathIsSafe(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    private function addString(ZipArchive $zip, string $archivePath, string $contents): void
    {
        if (! $zip->addFromString($archivePath, $contents)) {
            throw new RuntimeException('Unable to add export content to the archive.');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
