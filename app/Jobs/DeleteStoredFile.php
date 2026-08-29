<?php

namespace App\Jobs;

use App\Services\AuditRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeleteStoredFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public int $timeout = 60;

    public int $uniqueFor = 86400;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 300, 900, 3600, 10800];

    public function __construct(public readonly int $storedFileDeletionId)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return (string) $this->storedFileDeletionId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('stored-file-deletion:'.$this->storedFileDeletionId))
                ->expireAfter(120)
                ->dontRelease(),
        ];
    }

    public function handle(AuditRecorder $auditRecorder): void
    {
        $deletion = $this->deletionRecord();
        if ($deletion === null || $deletion['completed_at']) {
            return;
        }

        try {
            $path = $deletion['encrypted_path'] !== null
                ? Crypt::decryptString($deletion['encrypted_path'])
                : '';
            $disk = $deletion['disk'];
            if ($disk === '' || $path === '' || str_contains($path, '..')) {
                throw new RuntimeException('The stored file cleanup target is invalid.');
            }

            $storage = Storage::disk($disk);
            if ($storage->exists($path)) {
                $deleted = $storage->delete($path);
                if (! $deleted) {
                    throw new RuntimeException('The storage backend did not delete the file.');
                }
            }

            if (Storage::disk($disk)->exists($path)) {
                throw new RuntimeException('The stored file still exists after deletion.');
            }

            DB::table('stored_file_deletions')
                ->where('id', $this->storedFileDeletionId)
                ->update([
                    'encrypted_path' => null,
                    'last_attempted_at' => now(),
                    'last_error_code' => null,
                    'completed_at' => now(),
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);
            $auditRecorder->record(
                event: 'storage.file_cleanup_completed',
                metadata: [
                    'stored_file_deletion_id' => $this->storedFileDeletionId,
                    'disk' => $disk,
                    'path_fingerprint' => $deletion['path_hash'],
                    'reason' => $deletion['reason'],
                ],
            );
        } catch (Throwable $exception) {
            DB::table('stored_file_deletions')
                ->where('id', $this->storedFileDeletionId)
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_attempted_at' => now(),
                    'last_error_code' => 'storage_delete_failed',
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $deletion = $this->deletionRecord();
        if ($deletion === null || $deletion['completed_at']) {
            return;
        }

        DB::table('stored_file_deletions')
            ->where('id', $this->storedFileDeletionId)
            ->update([
                'failed_at' => now(),
                'last_error_code' => 'retries_exhausted',
                'updated_at' => now(),
            ]);
        app(AuditRecorder::class)->record(
            event: 'storage.file_cleanup_failed',
            metadata: [
                'stored_file_deletion_id' => $this->storedFileDeletionId,
                'disk' => $deletion['disk'],
                'path_fingerprint' => $deletion['path_hash'],
                'reason' => $deletion['reason'],
            ],
        );
    }

    /**
     * @return array{disk: string, path_hash: string, encrypted_path: string|null, reason: string, completed_at: bool}|null
     */
    private function deletionRecord(): ?array
    {
        $deletion = DB::table('stored_file_deletions')
            ->select(['disk', 'path_hash', 'encrypted_path', 'reason', 'completed_at'])
            ->find($this->storedFileDeletionId);
        if ($deletion === null) {
            return null;
        }

        $encryptedPath = data_get($deletion, 'encrypted_path');

        return [
            'disk' => (string) data_get($deletion, 'disk'),
            'path_hash' => (string) data_get($deletion, 'path_hash'),
            'encrypted_path' => is_string($encryptedPath) ? $encryptedPath : null,
            'reason' => (string) data_get($deletion, 'reason'),
            'completed_at' => data_get($deletion, 'completed_at') !== null,
        ];
    }
}
