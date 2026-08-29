<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\UserProfile;
use App\Services\PublicWebsitePublicationQuery;
use Illuminate\Contracts\View\View;

class PublicCommentRepliesController extends Controller
{
    public function __invoke(
        string $username,
        string $publicationSlug,
        int $comment,
        PublicWebsitePublicationQuery $websitePublications,
    ): View {
        $profile = UserProfile::query()
            ->with('user:id')
            ->where('username', $username)
            ->where('is_public', true)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'))
            ->firstOrFail(['id', 'user_id', 'username', 'display_name']);

        $publication = $websitePublications
            ->query()
            ->whereBelongsTo($profile->user, 'owner')
            ->where('slug', $publicationSlug)
            ->where('comments_enabled', true)
            ->firstOrFail(['id', 'user_id', 'title', 'slug']);

        $parentComment = Comment::query()
            ->approved()
            ->whereBelongsTo($publication)
            ->whereNull('parent_id')
            ->with('author:id,name')
            ->findOrFail($comment);

        $replies = Comment::query()
            ->approved()
            ->whereBelongsTo($publication)
            ->whereBelongsTo($parentComment, 'parent')
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20, pageName: 'replies_page');

        return view('public.comment-replies', compact(
            'parentComment',
            'profile',
            'publication',
            'replies',
        ));
    }
}
