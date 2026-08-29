<?php

namespace App\Http\Controllers;

use App\Actions\PublishPublication;
use App\Actions\UnpublishPublication;
use App\Http\Requests\PublishPublicationRequest;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

use function Illuminate\Support\enum_value;

class PublishedPublicationController extends Controller
{
    public function store(
        PublishPublicationRequest $request,
        Publication $publication,
        PublishPublication $publishPublication,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $publication = $publishPublication->handle(
            publication: $publication,
            owner: $request->user(),
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
                'published_at' => $publication->published_at?->toIso8601String(),
            ]]);
        }

        return redirect()->route('filament.app.resources.publications.edit', ['record' => $publication])
            ->with('status', __('Publication queued for the selected targets.'));
    }

    public function destroy(
        Request $request,
        Publication $publication,
        UnpublishPublication $unpublishPublication,
    ): RedirectResponse {
        Gate::authorize('publish', $publication);
        $unpublishPublication->handle($publication, $request->user());

        return back()->with('status', __('The website publication is now private. External copies may remain.'));
    }
}
