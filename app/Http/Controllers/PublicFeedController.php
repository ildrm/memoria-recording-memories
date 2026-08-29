<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PublicWebsitePublicationQuery;
use Illuminate\Http\Response;

class PublicFeedController extends Controller
{
    public function show(
        string $username,
        PublicWebsitePublicationQuery $websitePublications,
    ): Response {
        $profile = UserProfile::query()
            ->where('username', $username)
            ->where('is_public', true)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'))
            ->firstOrFail();
        $owner = User::query()->select(['id', 'name'])->findOrFail($profile->user_id);
        $publications = $websitePublications
            ->query()
            ->whereBelongsTo($owner, 'owner')
            ->select(['id', 'user_id', 'title', 'slug', 'excerpt', 'published_at', 'updated_at'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $title = $this->xml($profile->display_name ?: $owner->name);
        $profileUrl = route('profiles.show', $profile->username);
        $items = $publications->map(function (Publication $publication) use ($profile): string {
            $url = route('publications.show', [$profile->username, $publication->slug]);

            return '<item>'
                .'<title>'.$this->xml($publication->title).'</title>'
                .'<link>'.$this->xml($url).'</link>'
                .'<guid isPermaLink="true">'.$this->xml($url).'</guid>'
                .'<description>'.$this->xml((string) $publication->excerpt).'</description>'
                .'<pubDate>'.$publication->published_at->toRssString().'</pubDate>'
                .'</item>';
        })->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            .'<title>'.$title.'</title>'
            .'<link>'.$this->xml($profileUrl).'</link>'
            .'<description>'.$this->xml((string) $profile->biography).'</description>'
            .$items
            .'</channel></rss>';

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
