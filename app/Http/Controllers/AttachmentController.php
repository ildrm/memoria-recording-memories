<?php

namespace App\Http\Controllers;

use App\Actions\StoreAttachment;
use App\Enums\AttachmentScanStatus;
use App\Http\Requests\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Illuminate\Support\enum_value;

class AttachmentController extends Controller
{
    public function store(
        StoreAttachmentRequest $request,
        Entry $entry,
        StoreAttachment $storeAttachment,
    ): JsonResponse {
        $attachment = $storeAttachment->handle(
            $request->file('file'),
            $entry,
            $request->user(),
        );

        return response()->json(['data' => [
            'id' => $attachment->getKey(),
            'name' => $attachment->download_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'scan_status' => enum_value($attachment->scan_status),
        ]], 201);
    }

    public function show(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment);

        abort_unless($attachment->scan_status === AttachmentScanStatus::Clean, 423);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $this->safeFilename($attachment->download_name ?: $attachment->original_name),
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    private function safeFilename(string $filename): string
    {
        return str_replace(["\r", "\n", '"', '/', '\\'], '-', basename($filename));
    }
}
