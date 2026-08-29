<?php

namespace App\Http\Controllers;

use App\Actions\CancelPublicationSchedule;
use App\Actions\SchedulePublication;
use App\Http\Requests\SchedulePublicationRequest;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

use function Illuminate\Support\enum_value;

class ScheduledPublicationController extends Controller
{
    public function store(
        SchedulePublicationRequest $request,
        Publication $publication,
        SchedulePublication $schedulePublication,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $publication = $schedulePublication->handle(
            publication: $publication,
            owner: $request->user(),
            scheduledAt: $validated['scheduled_at'],
            timezone: $validated['timezone'],
            privacyReviewConfirmed: (bool) $validated['privacy_review_confirmed'],
            previewConfirmed: (bool) $validated['preview_confirmed'],
            publishToWebsite: (bool) ($validated['publish_to_website'] ?? true),
            socialProviders: $validated['social_providers'] ?? [],
            providerText: Arr::only(
                $validated['provider_text'] ?? [],
                $validated['social_providers'] ?? [],
            ),
            socialAccountIds: $validated['social_account_ids'] ?? [],
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $publication->getKey(),
                'status' => enum_value($publication->status),
                'scheduled_at' => $publication->scheduled_at === null
                    ? null
                    : CarbonImmutable::parse($publication->scheduled_at)->toIso8601String(),
            ]]);
        }

        return back()->with('status', __('Publication scheduled.'));
    }

    public function destroy(
        Request $request,
        Publication $publication,
        CancelPublicationSchedule $cancelPublicationSchedule,
    ): RedirectResponse {
        Gate::authorize('publish', $publication);
        $cancelPublicationSchedule->handle($publication, $request->user());

        return back()->with('status', __('Publication schedule cancelled.'));
    }
}
