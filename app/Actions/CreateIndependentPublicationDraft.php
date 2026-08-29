<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Events\PublicationDraftCreated;
use App\Models\Publication;
use App\Models\PublicationVersion;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateIndependentPublicationDraft
{
    /** @var array<int, string> */
    private const WRITABLE_ATTRIBUTES = [
        'title',
        'slug',
        'excerpt',
        'body',
        'topics',
        'comments_enabled',
        'reactions_enabled',
        'search_engine_indexing',
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $owner, array $attributes): Publication
    {
        Gate::forUser($owner)->authorize('create', Publication::class);

        $attributes = Arr::only($attributes, self::WRITABLE_ATTRIBUTES);
        if (is_string($attributes['title'] ?? null)) {
            $attributes['title'] = trim($attributes['title']);
        }

        $attributes = Validator::make($attributes, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('publications', 'slug')
                    ->where(fn ($query) => $query->where('user_id', $owner->getKey())),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:'.(int) config('memoria.rich_text.maximum_characters', 125000)],
            'topics' => ['sometimes', 'array', 'max:10'],
            'topics.*' => ['required', 'string', 'max:50'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'reactions_enabled' => ['sometimes', 'boolean'],
            'search_engine_indexing' => ['sometimes', 'boolean'],
        ])->validate();

        $attributes['excerpt'] = filled($attributes['excerpt'] ?? null)
            ? trim((string) $attributes['excerpt'])
            : null;
        $attributes['topics'] = $this->normalizeTopics($attributes['topics'] ?? []);
        $attributes['comments_enabled'] = (bool) ($attributes['comments_enabled'] ?? false);
        $attributes['reactions_enabled'] = (bool) ($attributes['reactions_enabled'] ?? false);
        $attributes['search_engine_indexing'] = (bool) ($attributes['search_engine_indexing'] ?? false);

        return DB::transaction(function () use ($owner, $attributes): Publication {
            $publication = new Publication;
            $publication->forceFill([
                ...$attributes,
                'user_id' => $owner->getKey(),
                'source_entry_id' => null,
                'status' => PublicationStatus::Draft,
                'source_revision' => null,
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
                    'comments_enabled' => (bool) $publication->comments_enabled,
                    'reactions_enabled' => (bool) $publication->reactions_enabled,
                    'search_engine_indexing' => (bool) $publication->search_engine_indexing,
                    'topics' => $publication->topics,
                ],
                'reason' => 'created_independently',
            ]);
            $version->save();

            $this->auditRecorder->record(
                event: 'publication.draft_created',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'source_entry_id' => null,
                    'source_revision' => null,
                ],
            );

            PublicationDraftCreated::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
            );

            return $publication;
        });
    }

    /**
     * @param  array<int, mixed>  $topics
     * @return array<int, string>
     */
    private function normalizeTopics(array $topics): array
    {
        $normalized = [];
        $seen = [];

        foreach ($topics as $topic) {
            $topic = trim(strip_tags((string) $topic));
            $topic = preg_replace('/[\x00-\x1F\x7F]/u', '', $topic) ?? '';
            if ($topic === '') {
                continue;
            }

            $key = Str::lower($topic);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $topic;
        }

        return $normalized;
    }
}
