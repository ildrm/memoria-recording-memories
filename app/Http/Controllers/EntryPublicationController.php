<?php

namespace App\Http\Controllers;

use App\Actions\CreatePublicationDraft;
use App\Filament\App\Resources\PublicationResource;
use App\Http\Requests\CreatePublicationRequest;
use App\Models\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

use function Illuminate\Support\enum_value;

class EntryPublicationController extends Controller
{
    public function store(
        CreatePublicationRequest $request,
        Entry $entry,
        CreatePublicationDraft $createPublicationDraft,
    ): JsonResponse|RedirectResponse {
        $publication = $createPublicationDraft->handle(
            entry: $entry,
            owner: $request->user(),
            title: $request->validated('title'),
            excerpt: $request->validated('excerpt'),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $publication->getKey(),
                    'status' => enum_value($publication->status),
                    'edit_url' => PublicationResource::getUrl('edit', ['record' => $publication]),
                    'privacy_review_url' => route('app.publications.privacy-review', $publication),
                ],
            ], 201);
        }

        return redirect()->to(PublicationResource::getUrl('edit', ['record' => $publication]));
    }
}
