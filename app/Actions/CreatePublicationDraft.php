<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Events\PublicationDraftCreated;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationVersion;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreatePublicationDraft
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        Entry $entry,
        User $owner,
        ?string $title = null,
        ?string $excerpt = null,
    ): Publication {
        Gate::forUser($owner)->authorize('publish', $entry);

        return DB::transaction(function () use ($entry, $owner, $title, $excerpt): Publication {
            $entry = Entry::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            $publicTitle = filled($title) ? trim((string) $title) : trim((string) $entry->title);
            $publicTitle = $publicTitle !== '' ? $publicTitle : __('Untitled memory');

            $publication = new Publication;
            $publication->forceFill([
                'user_id' => $owner->getKey(),
                'source_entry_id' => $entry->getKey(),
                'title' => $publicTitle,
                'slug' => $this->uniqueSlug($owner, $publicTitle),
                'excerpt' => filled($excerpt)
                    ? trim((string) $excerpt)
                    : Str::limit(trim(strip_tags((string) $entry->body)), 280),
                'body' => (string) $entry->body,
                'topics' => [],
                'status' => PublicationStatus::Draft,
                'source_revision' => $entry->revision,
                'revision' => 1,
            ]);
            $publication->save();

            $version = new PublicationVersion;
            $version->forceFill([
                'publication_id' => $publication->getKey(),
                'user_id' => $owner->getKey(),
                'version' => 1,
                'title' => $publication->title,
                'excerpt' => $publication->excerpt,
                'body' => $publication->body,
                'status' => PublicationStatus::Draft,
                'settings' => [
                    'comments_enabled' => false,
                    'reactions_enabled' => false,
                    'search_engine_indexing' => false,
                    'topics' => [],
                ],
                'reason' => 'created_from_entry',
            ]);
            $version->save();

            $this->auditRecorder->record(
                event: 'publication.draft_created',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'source_entry_id' => $entry->getKey(),
                    'source_revision' => $entry->revision,
                ],
            );

            PublicationDraftCreated::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
            );

            return $publication;
        });
    }

    private function uniqueSlug(User $owner, string $title): string
    {
        $base = Str::slug($title) ?: 'memory';
        $candidate = Str::limit($base, 160, '');

        if (! Publication::query()->ownedBy($owner)->where('slug', $candidate)->exists()) {
            return $candidate;
        }

        do {
            $candidate = Str::limit($base, 150, '').'-'.Str::lower(Str::random(8));
        } while (Publication::query()->ownedBy($owner)->where('slug', $candidate)->exists());

        return $candidate;
    }
}
