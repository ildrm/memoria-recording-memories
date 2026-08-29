<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Services\ShareLinkAccessSession;
use App\Services\ShareLinkResolver;
use App\Services\ShareLinks\InvalidShareLink;
use App\Services\ShareLinks\InvalidSharePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SharedAttachmentController extends Controller
{
    public function show(
        Request $request,
        string $token,
        Attachment $attachment,
        ShareLinkResolver $resolver,
        ShareLinkAccessSession $accessSession,
    ): StreamedResponse {
        $hasAccessSession = $accessSession->isGranted($request, $token);

        try {
            $shareLink = $resolver->resolve(
                token: $token,
                recordView: ! $hasAccessSession,
                passwordVerified: $hasAccessSession,
                viewAlreadyCounted: $hasAccessSession,
            );
        } catch (InvalidShareLink|InvalidSharePassword) {
            abort(404);
        }

        $accessSession->grant($request, $token);

        abort_unless(
            $shareLink->include_attachments
            && $shareLink->entry_id !== null
            && (int) $attachment->entry_id === (int) $shareLink->entry_id,
            404,
        );
        abort_unless($attachment->scan_status === AttachmentScanStatus::Clean, 423);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            str_replace(["\r", "\n", '"', '/', '\\'], '-', basename($attachment->download_name ?: $attachment->original_name)),
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
