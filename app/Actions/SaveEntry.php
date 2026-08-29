<?php

namespace App\Actions;

use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\User;
use App\Services\EntryVersioner;
use App\Services\LocalDateTimeResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveEntry
{
    /**
     * @var array<int, string>
     */
    private const WRITABLE_ATTRIBUTES = [
        'journal_id',
        'title',
        'body',
        'occurred_at',
        'timezone',
        'mood',
        'custom_mood',
        'location_name',
        'latitude',
        'longitude',
        'importance',
        'status',
        'is_favorite',
        'archived_at',
    ];

    public function __construct(
        private readonly EntryVersioner $versioner,
        private readonly LocalDateTimeResolver $localDateTimeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $owner,
        ?Entry $entry,
        array $attributes,
        ?int $expectedRevision = null,
        bool $autosave = false,
    ): Entry {
        if ($entry !== null) {
            Gate::forUser($owner)->authorize('update', $entry);
        } else {
            Gate::forUser($owner)->authorize('create', Entry::class);
        }

        return DB::transaction(function () use (
            $owner,
            $entry,
            $attributes,
            $expectedRevision,
            $autosave,
        ): Entry {
            $entry = $entry === null
                ? new Entry
                : Entry::query()->ownedBy($owner)->lockForUpdate()->findOrFail($entry->getKey());

            if ($entry->exists
                && $expectedRevision !== null
                && (int) $entry->revision !== $expectedRevision
            ) {
                throw ValidationException::withMessages([
                    'revision' => [__('This memory changed in another session. Reload before saving again.')],
                ]);
            }

            $attributes = Arr::only($attributes, self::WRITABLE_ATTRIBUTES);
            $this->ensureRenderableBody($attributes['body'] ?? null);
            $this->ensureOwnedJournal($owner, $attributes['journal_id'] ?? null);
            $this->normalizeOccurredAt($attributes, $entry);

            if (! $entry->exists) {
                $entry->forceFill([
                    'user_id' => $owner->getKey(),
                    'status' => EntryStatus::Draft,
                    'revision' => 1,
                ]);
            }

            $entry->fill($attributes);
            $hasMeaningfulChanges = $entry->isDirty(self::WRITABLE_ATTRIBUTES);

            if (! $entry->exists || $hasMeaningfulChanges) {
                if ($entry->exists) {
                    $entry->revision = (int) $entry->revision + 1;
                }

                $entry->last_saved_at = now();
                $entry->save();

                if (! $autosave || $this->versioner->shouldCaptureAutosave($entry)) {
                    $this->versioner->snapshot($entry, $autosave ? 'autosave' : 'manual_save');
                }
            }

            return $entry->refresh();
        });
    }

    private function ensureRenderableBody(mixed $body): void
    {
        if (! is_string($body) || mb_strlen($body) <= (int) config('memoria.rich_text.maximum_characters', 125000)) {
            return;
        }

        throw ValidationException::withMessages([
            'body' => [__('This memory is too large to save safely. Shorten it and try again.')],
        ]);
    }

    private function ensureOwnedJournal(User $owner, mixed $journalId): void
    {
        if ($journalId === null || $journalId === '') {
            return;
        }

        if (! Journal::query()->ownedBy($owner)->whereKey($journalId)->exists()) {
            throw ValidationException::withMessages([
                'journal_id' => [__('The selected journal is unavailable.')],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function normalizeOccurredAt(array &$attributes, Entry $entry): void
    {
        if (! array_key_exists('occurred_at', $attributes)) {
            return;
        }

        if ($attributes['occurred_at'] === null || $attributes['occurred_at'] === '') {
            $attributes['occurred_at'] = null;

            return;
        }

        $timezone = (string) ($attributes['timezone'] ?? $entry->timezone ?? 'UTC');
        $attributes['occurred_at'] = $this->localDateTimeResolver->resolve(
            $attributes['occurred_at'],
            $timezone,
            'occurred_at',
        );
    }
}
