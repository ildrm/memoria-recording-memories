<?php

namespace App\Http\Controllers;

use App\Models\PublicationMedia;
use App\Services\PublicWebsitePublicationQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PublicPublicationMediaController extends Controller
{
    public function show(
        Request $request,
        PublicationMedia $publicationMedia,
        PublicWebsitePublicationQuery $websitePublications,
        ?string $variant = null,
    ): Response {
        $publicationIsPublic = $websitePublications
            ->query()
            ->whereKey($publicationMedia->publication_id)
            ->whereHas('owner.profile', fn ($query) => $query
                ->where('is_public', true)
                ->whereNotNull('username'))
            ->whereHas('owner', fn ($owner) => $owner->whereNull('disabled_at'))
            ->exists();

        abort_unless($publicationIsPublic, 404);
        $image = $publicationMedia->imageVariant($variant);
        abort_unless($image !== null, 404);
        abort_unless(Storage::disk($image['disk'])->exists($image['path']), 404);

        $response = Storage::disk($image['disk'])->response(
            $image['path'],
            null,
            [
                'Content-Type' => $image['mime_type'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, no-cache, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'Vary' => 'Accept-Encoding',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
        $response->setEtag(hash('sha256', implode("\0", [
            (string) $publicationMedia->getKey(),
            $image['name'],
            $image['path'],
            (string) $image['size_bytes'],
        ])));
        $response->isNotModified($request);

        return $response;
    }
}
