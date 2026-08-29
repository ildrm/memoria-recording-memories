<?php

namespace App\Http\Controllers;

use App\Actions\RecordPublicationPreview;
use App\Models\Publication;
use App\Services\PublicationWorkflowConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublicationPreviewController extends Controller
{
    public function show(
        Request $request,
        Publication $publication,
        PublicationWorkflowConfirmation $workflowConfirmation,
    ): View {
        Gate::authorize('update', $publication);
        $publication->loadMissing(['owner.profile', 'media']);
        $workflowConfirmation->assertReadyToPreview($publication, $request->user());

        try {
            $workflowConfirmation->assertReadyToPublish($publication);
            $previewConfirmed = true;
        } catch (ValidationException) {
            $previewConfirmed = false;
        }

        return view('public.preview', compact('publication', 'previewConfirmed'));
    }

    public function store(
        Request $request,
        Publication $publication,
        RecordPublicationPreview $recordPublicationPreview,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $publication);
        $recordPublicationPreview->handle($publication, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'publication_id' => $publication->getKey(),
                'preview_confirmed' => true,
            ]]);
        }

        return redirect()->route('app.publications.preview', $publication)
            ->with('status', __('Preview confirmed for this exact public version.'));
    }
}
