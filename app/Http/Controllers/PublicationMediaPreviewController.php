<?php

namespace App\Http\Controllers;

use App\Models\PublicationMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicationMediaPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        PublicationMedia $publicationMedia,
        ?string $variant = null,
    ): StreamedResponse {
        Gate::authorize('update', $publicationMedia);
        $publicationMedia = PublicationMedia::query()
            ->ownedBy($request->user())
            ->findOrFail($publicationMedia->getKey());

        $image = $publicationMedia->imageVariant($variant);
        abort_unless($image !== null, 404);
        abort_unless(Storage::disk($image['disk'])->exists($image['path']), 404);

        return Storage::disk($image['disk'])->response(
            $image['path'],
            null,
            [
                'Content-Type' => $image['mime_type'],
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
