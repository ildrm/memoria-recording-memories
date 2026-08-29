<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Filament\App\Resources\PublicationResource;
use App\Models\Publication;
use App\Services\PublicationPrivacyReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PublicationPrivacyReviewController extends Controller
{
    public function show(
        Request $request,
        Publication $publication,
        PublicationPrivacyReview $privacyReview,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $publication);

        if (! $request->expectsJson()) {
            return redirect()->to(PublicationResource::getUrl('edit', [
                'record' => $publication,
                'action' => 'privacyReview',
            ]));
        }

        return response()->json([
            'data' => [
                'warnings' => $privacyReview->warnings($publication),
                'disclaimer' => __('Automated warnings are incomplete. Review the entire public version deliberately.'),
            ],
        ]);
    }

    public function store(
        Request $request,
        Publication $publication,
        ConfirmPublicationPrivacyReview $confirmPrivacyReview,
    ): JsonResponse|RedirectResponse {
        $confirmPrivacyReview->handle($publication, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'publication_id' => $publication->getKey(),
                'privacy_review_confirmed' => true,
            ]]);
        }

        return redirect()->route('app.publications.preview', $publication)
            ->with('status', __('Privacy review confirmed for this public version.'));
    }
}
