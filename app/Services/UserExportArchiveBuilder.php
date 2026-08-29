<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\EntryVersion;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
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
    public function __construct(private readonly Filesystem $files) {}

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
        $zip = new ZipArchive;

        try {
            if ($zip->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create export archive.');
            }

            $options = $export->options ?? [];
            $formats = array_intersect((array) ($options['formats'] ?? ['json', 'markdown']), ['json', 'markdown']);
            $includeAttachments = (bool) ($options['include_attachments'] ?? true);

            $counts = [
                'journals' => $this->addJournals($zip, $owner, $workingDirectory, $temporaryFiles),
                'entries' => $this->addEntries($zip, $owner, $formats),
                'entry_versions' => $this->addEntryVersions($zip, $owner, $workingDirectory, $temporaryFiles),
                'tags' => $this->addTags($zip, $owner, $workingDirectory, $temporaryFiles),
                'people' => $this->addPeople($zip, $owner, $workingDirectory, $temporaryFiles),
                'publications' => $this->addPublications($zip, $owner),
                'publication_versions' => $this->addPublicationVersions($zip, $owner, $workingDirectory, $temporaryFiles),
                'shares' => $this->addShares($zip, $owner, $workingDirectory, $temporaryFiles),
                'reminders' => $this->addReminders($zip, $owner, $workingDirectory, $temporaryFiles),
                'community' => $this->addCommunityActivity($zip, $owner, $workingDirectory, $temporaryFiles),
                'social' => $this->addSocialMetadata($zip, $owner, $workingDirectory, $temporaryFiles),
                'attachments' => $includeAttachments
                    ? $this->addAttachments($zip, $owner, $workingDirectory, $temporaryFiles)
                    : 0,
            ];

            $owner->loadMissing(['profile', 'preferences']);
            $zip->addFromString('metadata/account.json', $this->json([
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'email' => $owner->email,
                'profile' => $owner->profile?->only([
                    'username',
                    'display_name',
                    'biography',
                    'website_url',
                    'is_public',
                ]),
                'preferences' => $owner->preferences?->only([
                    'locale',
                    'timezone',
                    'appearance',
                    'on_this_day_enabled',
                    'notification_preferences',
                    'privacy_preferences',
                ]),
            ]));
            $zip->addFromString('manifest.json', $this->json([
                'schema' => 'memoria-export-v1',
                'exported_at' => now()->toIso8601String(),
                'formats' => array_values($formats),
                'includes_original_attachments' => $includeAttachments,
                'counts' => $counts,
            ]));
            $zip->close();

            $disk = (string) config('memoria.disks.exports', 'local');
            $directory = trim((string) config('memoria.exports.directory', 'exports'), '/');
            $filename = 'memoria-export-'.now()->format('Y-m-d').'-'.Str::lower(Str::random(8)).'.zip';
            $path = $directory.'/'.$owner->getKey().'/'.$export->getKey().'/'.$filename;
            $stream = fopen($temporaryArchive, 'rb');

            if ($stream === false || ! Storage::disk($disk)->writeStream($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                throw new RuntimeException('Unable to store export archive.');
            }

            fclose($stream);

            return [
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename,
                'size' => (int) Storage::disk($disk)->size($path),
            ];
        } catch (Throwable $exception) {
            if ($zip->status === ZipArchive::ER_OK) {
                $zip->close();
            }

            throw $exception;
        } finally {
            foreach ($temporaryFiles as $temporaryPath) {
                $this->files->delete($temporaryPath);
            }

            $this->files->delete($temporaryArchive);
        }
    }

    /** @param array<int, string> $temporaryFiles */
    private function addJournals(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int
    {
        $journals = Journal::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Journal $journal): array => $journal->only([
                'id', 'name', 'slug', 'description', 'icon', 'sort_order', 'archived_at',
                'created_at', 'updated_at', 'deleted_at',
            ]));

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
                            $zip->addFromString("entries/{$entry->getKey()}.json", $this->json($payload));
                        }

                        if (in_array('markdown', $formats, true)) {
                            $zip->addFromString(
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
                    $zip->addFromString(
                        "publications/{$publication->getKey()}.json",
                        $this->json($publication->only([
                            'id', 'source_entry_id', 'title', 'slug', 'excerpt', 'body',
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
    private function addEntryVersions(
        ZipArchive $zip,
        User $owner,
        string $workingDirectory,
        array &$temporaryFiles,
    ): int
    {
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
    ): int
    {
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
    ): int
    {
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
    ): int
    {
        $people = Person::withTrashed()
            ->ownedBy($owner)
            ->lazyById($this->chunkSize(), 'id')
            ->map(fn (Person $person): array => $person->only([
                'id', 'display_name', 'nickname', 'notes', 'relationship',
                'created_at', 'updated_at', 'deleted_at',
            ]));

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

        Attachment::withTrashed()
            ->ownedBy($owner)
            ->orderBy('id')
            ->chunkById($this->chunkSize(), function ($attachments) use (
                $zip,
                $workingDirectory,
                &$temporaryFiles,
                &$count,
            ): void {
                foreach ($attachments as $attachment) {
                    $source = Storage::disk($attachment->disk)->readStream($attachment->path);
                    if (! is_resource($source)) {
                        continue;
                    }

                    $temporaryPath = tempnam($workingDirectory, 'media-');
                    if ($temporaryPath === false) {
                        fclose($source);

                        throw new RuntimeException('Unable to allocate media export workspace.');
                    }

                    $destination = fopen($temporaryPath, 'wb');
                    if ($destination === false) {
                        fclose($source);

                        throw new RuntimeException('Unable to write media export workspace.');
                    }

                    stream_copy_to_stream($source, $destination);
                    fclose($source);
                    fclose($destination);
                    $temporaryFiles[] = $temporaryPath;

                    $safeName = Str::slug(pathinfo($attachment->original_name, PATHINFO_FILENAME)) ?: 'attachment';
                    $extension = preg_replace('/[^a-z0-9]/i', '', (string) $attachment->extension);
                    $archiveName = "media/{$attachment->entry_id}/{$attachment->getKey()}-{$safeName}";
                    if ($extension !== '') {
                        $archiveName .= '.'.Str::lower($extension);
                    }

                    $zip->addFile($temporaryPath, $archiveName);
                    $count++;
                }
            }, 'id');

        return $count;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
