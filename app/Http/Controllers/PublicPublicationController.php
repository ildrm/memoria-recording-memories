<?php

namespace App\Http\Controllers;

use App\Enums\ReactionType;
use App\Models\Comment;
use App\Models\UserProfile;
use App\Services\PublicWebsitePublicationQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicPublicationController extends Controller
{
    public function show(
        Request $request,
        string $username,
        string $publicationSlug,
        PublicWebsitePublicationQuery $websitePublications,
    ): View {
        $profile = UserProfile::query()
            ->with('user:id,name')
            ->where('username', $username)
            ->where('is_public', true)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'))
            ->firstOrFail();

        $publication = $websitePublications
            ->query()
            ->whereBelongsTo($profile->user, 'owner')
            ->where('slug', $publicationSlug)
            ->select([
                'id', 'user_id', 'title', 'slug', 'excerpt', 'body', 'topics',
                'comments_enabled', 'reactions_enabled', 'search_engine_indexing',
                'published_at', 'updated_at',
            ])
            ->with([
                'owner.profile:id,user_id,username,display_name,avatar_path,biography',
                'media:id,publication_id,disk,path,original_name,mime_type,size_bytes,alt_text,sort_order,is_featured,metadata_stripped,metadata',
            ])
            ->withCount([
                'comments as approved_comments_count' => fn ($comments) => $comments->approved(),
                'reactions',
                'reactions as like_reactions_count' => fn ($reactions) => $reactions->where('type', ReactionType::Like),
                'reactions as love_reactions_count' => fn ($reactions) => $reactions->where('type', ReactionType::Love),
                'reactions as support_reactions_count' => fn ($reactions) => $reactions->where('type', ReactionType::Support),
                'reactions as insightful_reactions_count' => fn ($reactions) => $reactions->where('type', ReactionType::Insightful),
            ])
            ->firstOrFail();

        $comments = Comment::query()
            ->approved()
            ->whereBelongsTo($publication)
            ->whereNull('parent_id')
            ->select(['id', 'publication_id', 'user_id', 'parent_id', 'body', 'status', 'created_at'])
            ->withCount([
                'replies as approved_replies_count' => fn ($replies) => $replies
                    ->approved()
                    ->where('publication_id', $publication->getKey()),
            ])
            ->with([
                'author:id,name',
                'replies' => fn ($replies) => $replies
                    ->approved()
                    ->where('publication_id', $publication->getKey())
                    ->select(['id', 'publication_id', 'user_id', 'parent_id', 'body', 'status', 'created_at'])
                    ->with('author:id,name')
                    ->oldest()
                    ->limit(10),
            ])
            ->latest()
            ->paginate(20, pageName: 'comments_page');

        if (! $publication->search_engine_indexing) {
            $request->attributes->set('memoria.noindex', true);
        }

        return view('public.publication', compact('profile', 'publication', 'comments'));
    }
}
