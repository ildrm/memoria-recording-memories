<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicProfileImageController extends Controller
{
    public function show(string $username, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['avatar', 'cover'], true), 404);
        $profile = UserProfile::query()
            ->where('username', $username)
            ->where('is_public', true)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'))
            ->select([
                'id', 'user_id', 'avatar_path', 'avatar_disk',
                'cover_image_path', 'cover_image_disk',
            ])
            ->firstOrFail();
        $path = $kind === 'avatar' ? $profile->avatar_path : $profile->cover_image_path;
        $configuredDisk = $kind === 'avatar' ? $profile->avatar_disk : $profile->cover_image_disk;

        abort_unless(is_string($path) && $path !== '' && ! str_contains($path, '..'), 404);
        $disk = is_string($configuredDisk) && $configuredDisk !== ''
            ? $configuredDisk
            : (string) config('memoria.disks.sanitized_media', 'local');
        abort_if($disk === (string) config('memoria.disks.public', 'public'), 404);
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $mimeType = $storage->mimeType($path);
        abort_unless(is_string($mimeType) && in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true), 404);

        return $storage->response($path, null, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
