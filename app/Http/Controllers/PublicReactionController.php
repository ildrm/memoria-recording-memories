<?php

namespace App\Http\Controllers;

use App\Actions\SetPublicReaction;
use App\Enums\ReactionType;
use App\Http\Requests\StorePublicReactionRequest;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PublicReactionController extends Controller
{
    public function store(
        StorePublicReactionRequest $request,
        Publication $publication,
        SetPublicReaction $setPublicReaction,
    ): JsonResponse|RedirectResponse {
        $reaction = $setPublicReaction->handle(
            publication: $publication,
            actor: $request->user(),
            type: ReactionType::from($request->validated('type')),
            request: $request,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $reaction->getKey(),
                'type' => $reaction->type instanceof ReactionType
                    ? $reaction->type->value
                    : (string) $reaction->type,
            ]], $reaction->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with('status', __('Reaction saved.'));
    }
}
