<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use App\Services\PublicWebsitePublicationQuery;
use Illuminate\Contracts\View\View;

class PublicProfileController extends Controller
{
    public function show(
        string $username,
        PublicWebsitePublicationQuery $websitePublications,
    ): View {
        $profile = UserProfile::query()
            ->with('user:id,name')
            ->where('username', $username)
            ->where('is_public', true)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'))
            ->firstOrFail();

        $publications = $websitePublications
            ->query()
            ->whereBelongsTo($profile->user, 'owner')
            ->select([
                'id', 'user_id', 'title', 'slug', 'excerpt', 'published_at',
                'search_engine_indexing',
            ])
            ->with('featuredMedia:id,publication_id,disk,path,mime_type,size_bytes,alt_text,metadata_stripped,metadata')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12);

        return view('public.profile', compact('profile', 'publications'));
    }
}
