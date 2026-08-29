<?php

namespace App\Http\Controllers;

use App\Actions\SaveEntry;
use App\Http\Requests\SaveEntryRequest;
use App\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EntryController extends Controller
{
    public function store(
        SaveEntryRequest $request,
        SaveEntry $saveEntry,
    ): JsonResponse|RedirectResponse {
        return $this->persist($request, $saveEntry, null);
    }

    public function update(
        SaveEntryRequest $request,
        Entry $entry,
        SaveEntry $saveEntry,
    ): JsonResponse|RedirectResponse {
        return $this->persist($request, $saveEntry, $entry);
    }

    private function persist(
        SaveEntryRequest $request,
        SaveEntry $saveEntry,
        ?Entry $entry,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $entry = $saveEntry->handle(
            owner: $request->user(),
            entry: $entry,
            attributes: $request->safe()->except(['expected_revision', 'autosave']),
            expectedRevision: isset($validated['expected_revision'])
                ? (int) $validated['expected_revision']
                : null,
            autosave: (bool) ($validated['autosave'] ?? false),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $entry->getKey(),
                    'revision' => $entry->revision,
                    'last_saved_at' => $entry->last_saved_at === null
                        ? null
                        : CarbonImmutable::parse($entry->last_saved_at)->toIso8601String(),
                ],
            ], $entry->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with('status', __('Memory saved.'));
    }
}
