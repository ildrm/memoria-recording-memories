<?php

namespace App\Http\Controllers;

use App\Actions\CreatePublicComment;
use App\Http\Requests\StorePublicCommentRequest;
use App\Models\Comment;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

use function Illuminate\Support\enum_value;

class PublicCommentController extends Controller
{
    public function store(
        StorePublicCommentRequest $request,
        Publication $publication,
        CreatePublicComment $createPublicComment,
    ): JsonResponse|RedirectResponse {
        $parentId = $request->validated('parent_id');
        $parent = $parentId === null
            ? null
            : Comment::query()->findOrFail((int) $parentId);
        $comment = $createPublicComment->handle(
            publication: $publication,
            author: $request->user(),
            body: $request->validated('body'),
            parent: $parent,
            request: $request,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $comment->getKey(),
                'status' => enum_value($comment->status),
            ]], 201);
        }

        return back()->with('status', __('Your comment was submitted for review.'));
    }
}
