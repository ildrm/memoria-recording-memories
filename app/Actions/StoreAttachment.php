<?php

namespace App\Actions;

use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\StoredFileCleanup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StoreAttachment
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(
        UploadedFile $file,
        Entry $entry,
        User $owner,
    ): Attachment {
        Gate::forUser($owner)->authorize('update', $entry);
        Gate::forUser($owner)->authorize('create', Attachment::class);

        $disk = (string) config('memoria.disks.private', 'local');
        $path = $file->store("attachments/{$owner->getKey()}/{$entry->getKey()}", $disk);

        if ($path === false) {
            throw new RuntimeException('Unable to store the attachment.');
        }

        $mediaType = $this->mediaType((string) $file->getMimeType());
        $temporaryPath = $file->getRealPath();
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            $this->deleteOrSchedule($disk, $path, 'unreadable_attachment_upload');

            throw new RuntimeException('Unable to read the uploaded attachment.');
        }

        $sha256 = hash_file('sha256', $temporaryPath);
        if (! is_string($sha256)) {
            $this->deleteOrSchedule($disk, $path, 'unfingerprinted_attachment_upload');

            throw new RuntimeException('Unable to fingerprint the attachment.');
        }

        $attachment = new Attachment;
        $attachment->forceFill([
            'user_id' => $owner->getKey(),
            'entry_id' => $entry->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => basename($file->getClientOriginalName()),
            'download_name' => basename($file->getClientOriginalName()),
            'mime_type' => (string) $file->getMimeType(),
            'extension' => $file->guessExtension(),
            'size_bytes' => (int) $file->getSize(),
            'media_type' => $mediaType,
            'sha256' => $sha256,
            'scan_status' => AttachmentScanStatus::Pending,
            'metadata' => [],
        ]);

        try {
            DB::transaction(function () use ($attachment, $owner, $mediaType): void {
                $attachment->save();

                $this->auditRecorder->record(
                    event: 'attachment.uploaded',
                    actor: $owner,
                    auditable: $attachment,
                    metadata: [
                        'entry_id' => $attachment->entry_id,
                        'size_bytes' => (int) $attachment->size_bytes,
                        'media_type' => $mediaType->value,
                        'scan_status' => AttachmentScanStatus::Pending->value,
                    ],
                );

                ScanAttachment::dispatch((int) $attachment->getKey())->afterCommit();
            });
        } catch (\Throwable $exception) {
            $this->deleteOrSchedule($disk, $path, 'abandoned_attachment_upload');

            throw $exception;
        }

        return $attachment;
    }

    private function deleteOrSchedule(string $disk, string $path, string $reason): void
    {
        $storage = Storage::disk($disk);
        if ((! $storage->delete($path)) && $storage->exists($path)) {
            $this->storedFileCleanup->schedule($disk, $path, $reason);
        }
    }

    private function mediaType(string $mimeType): AttachmentMediaType
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => AttachmentMediaType::Image,
            str_starts_with($mimeType, 'audio/') => AttachmentMediaType::Audio,
            str_starts_with($mimeType, 'video/') => AttachmentMediaType::Video,
            $mimeType === 'application/pdf' || str_starts_with($mimeType, 'text/') => AttachmentMediaType::Document,
            default => AttachmentMediaType::Other,
        };
    }
}
