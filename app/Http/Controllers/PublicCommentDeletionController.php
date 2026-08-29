<?php

namespace App\Http\Controllers;

use App\Actions\DeletePublicComment;
use App\Models\Comment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicCommentDeletionController extends Controller
{
    public function __invoke(
        Request $request,
        Comment $comment,
        DeletePublicComment $deletePublicComment,
    ): JsonResponse|RedirectResponse {
        $deletePublicComment->handle($comment, $request->user(), $request);

        if ($request->expectsJson()) {
            return response()->json(status: 204);
        }

        return back()->with('status', __('Comment deleted.'));
    }
}
