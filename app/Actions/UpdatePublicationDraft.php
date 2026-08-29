<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Events\PublicationUnpublished;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationEditTransition;
use App\Services\PublicationSnapshotter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdatePublicationDraft
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

    public function __construct(
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly PublicationEditTransition $editTransition,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        Publication $publication,
        User $owner,
        array $attributes,
    ): Publication {
        Gate::forUser($owner)->authorize('update', $publication);

        return DB::transaction(function () use ($publication, $owner, $attributes): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            $attributes = Arr::only($attributes, self::WRITABLE_ATTRIBUTES);
            $attributes = Validator::make($attributes, [
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'slug' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:180',
                    'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    Rule::unique('publications', 'slug')
                        ->where(fn ($query) => $query->where('user_id', $owner->getKey()))
                        ->ignore($publication->getKey()),
                ],
                'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
                'body' => ['sometimes', 'required', 'string', 'max:'.(int) config('memoria.rich_text.maximum_characters', 125000)],
                'topics' => ['sometimes', 'array', 'max:10'],
                'topics.*' => ['required', 'string', 'max:50'],
                'comments_enabled' => ['sometimes', 'boolean'],
                'reactions_enabled' => ['sometimes', 'boolean'],
                'search_engine_indexing' => ['sometimes', 'boolean'],
            ])->validate();

            if (array_key_exists('topics', $attributes)) {
                $attributes['topics'] = $this->normalizeTopics($attributes['topics']);
            }

            $publication->fill($attributes);
            if (! $publication->isDirty(self::WRITABLE_ATTRIBUTES)) {
                return $publication;
            }

            $transition = $this->editTransition->apply($publication, 'publication_updated');
            $previousStatus = $transition['previous_status'];
            $visibilityWithdrawn = $transition['visibility_withdrawn'];
            $publication->save();

            $this->snapshotter->snapshot(
                $publication,
                $visibilityWithdrawn ? 'edited_and_withdrawn' : 'edited_public_version',
            );
            $this->auditRecorder->record(
                event: 'publication.updated',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'previous_status' => $previousStatus->value,
                    'current_status' => $publication->status instanceof PublicationStatus
                        ? $publication->status->value
                        : (string) $publication->status,
                    'visibility_withdrawn' => $visibilityWithdrawn,
                    'privacy_review_required' => true,
                ],
            );

            if ($previousStatus === PublicationStatus::Published) {
                PublicationUnpublished::dispatch(
                    (int) $publication->getKey(),
                    (int) $owner->getKey(),
                );
            }

            return $publication->refresh();
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
