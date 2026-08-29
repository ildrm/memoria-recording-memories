<?php

namespace App\Actions;

use App\Models\Entry;
use App\Models\EntryVersion;
use App\Models\User;
use App\Services\EntryVersioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreEntryVersion
{
    public function __construct(private readonly EntryVersioner $versioner) {}

    public function handle(Entry $entry, EntryVersion $version, User $owner): Entry
    {
        Gate::forUser($owner)->authorize('update', $entry);
        Gate::forUser($owner)->authorize('restore', $version);

        return DB::transaction(function () use ($entry, $version, $owner): Entry {
            $entry = Entry::query()->ownedBy($owner)->lockForUpdate()->findOrFail($entry->getKey());
            $version = EntryVersion::query()
                ->whereBelongsTo($entry)
                ->whereBelongsTo($owner, 'owner')
                ->findOrFail($version->getKey());
            Gate::forUser($owner)->authorize('restore', $version);

            $currentRevisionIsPreserved = EntryVersion::query()
                ->whereBelongsTo($entry)
                ->where('version', $entry->revision)
                ->exists();

            if (! $currentRevisionIsPreserved) {
                $this->versioner->snapshot($entry, 'before_version_restore');
            }

            $entry->forceFill([
                'title' => $version->title,
                'body' => $version->body,
                'occurred_at' => $version->occurred_at,
                'timezone' => $version->timezone,
                'mood' => $version->mood,
                'custom_mood' => $version->custom_mood,
                'location_name' => $version->location_name,
                'latitude' => $version->latitude,
                'longitude' => $version->longitude,
                'importance' => $version->importance,
                'revision' => (int) $entry->revision + 1,
                'last_saved_at' => now(),
            ])->save();

            $this->versioner->snapshot($entry, 'restored_version_'.$version->version);

            return $entry->refresh();
        });
    }
}
