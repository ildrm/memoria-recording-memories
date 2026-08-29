<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\EntryVersion;
use Carbon\CarbonImmutable;

class EntryVersioner
{
    public function snapshot(Entry $entry, string $reason): EntryVersion
    {
        $version = new EntryVersion;
        $version->forceFill([
            'entry_id' => $entry->getKey(),
            'user_id' => $entry->user_id,
            'version' => $entry->revision,
            'title' => $entry->title,
            'body' => $entry->body,
            'occurred_at' => $entry->occurred_at,
            'timezone' => $entry->timezone,
            'mood' => $entry->mood,
            'custom_mood' => $entry->custom_mood,
            'location_name' => $entry->location_name,
            'latitude' => $entry->latitude,
            'longitude' => $entry->longitude,
            'importance' => $entry->importance,
            'metadata' => [
                'journal_id' => $entry->journal_id,
                'status' => $entry->status instanceof \BackedEnum
                    ? $entry->status->value
                    : $entry->status,
                'is_favorite' => (bool) $entry->is_favorite,
                'archived_at' => $entry->archived_at === null
                    ? null
                    : CarbonImmutable::parse($entry->archived_at)->toIso8601String(),
            ],
            'reason' => $reason,
        ]);
        $version->save();

        return $version;
    }

    public function shouldCaptureAutosave(Entry $entry): bool
    {
        $latestVersion = EntryVersion::query()
            ->whereBelongsTo($entry)
            ->latest('created_at')
            ->first();
        if ($latestVersion === null) {
            return true;
        }

        $interval = max(
            1,
            (int) config('memoria.entries.autosave_version_interval_minutes', 15),
        );

        return $latestVersion->created_at->lte(now()->subMinutes($interval));
    }
}
