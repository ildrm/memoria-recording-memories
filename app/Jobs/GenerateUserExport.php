<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Events\ExportCompleted;
use App\Models\Export;
use App\Models\User;
use App\Notifications\ExportReadyNotification;
use App\Services\AuditRecorder;
use App\Services\NotificationPreference;
use App\Services\StoredFileCleanup;
use App\Services\UserExportArchiveBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateUserExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('export:'.$this->exportId))->expireAfter(960)->dontRelease()];
    }

    public function handle(
        UserExportArchiveBuilder $builder,
        AuditRecorder $auditRecorder,
        NotificationPreference $notificationPreference,
        StoredFileCleanup $storedFileCleanup,
    ): void {
        $export = DB::transaction(function (): ?Export {
            $export = Export::query()->lockForUpdate()->with('owner')->find($this->exportId);
            if ($export === null || ! in_array($export->status, [
                ExportStatus::Pending,
                ExportStatus::Failed,
            ], true)) {
                return null;
            }

            $export->forceFill([
                'status' => ExportStatus::Processing,
                'started_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            return $export;
        });

        if ($export === null) {
            return;
        }

        $owner = User::query()->find($export->user_id);
        if ($owner === null) {
            return;
        }

        $result = $builder->build($export, $owner);

        try {
            DB::transaction(function () use ($export, $result, $auditRecorder, $notificationPreference): void {
                $export = Export::query()->lockForUpdate()->with('owner')->findOrFail($export->getKey());
                $expiresAt = now()->addHours((int) config('memoria.exports.expiration_hours', 72));
                $owner = User::query()->findOrFail($export->user_id);
                $export->forceFill([
                    'status' => ExportStatus::Ready,
                    'disk' => $result['disk'],
                    'path' => $result['path'],
                    'filename' => $result['filename'],
                    'size_bytes' => $result['size'],
                    'completed_at' => now(),
                    'expires_at' => $expiresAt,
                ])->save();

                $auditRecorder->record(
                    event: 'export.completed',
                    actor: $owner,
                    auditable: $export,
                    metadata: ['size_bytes' => $result['size']],
                );
                if ($notificationPreference->allows($owner, 'export_ready')) {
                    $owner->notify(
                        (new ExportReadyNotification(
                            (int) $export->getKey(),
                            $expiresAt->toIso8601String(),
                        ))->afterCommit(),
                    );
                }
                ExportCompleted::dispatch((int) $export->getKey(), (int) $export->user_id);
            });
        } finally {
            $archiveIsTracked = false;

            try {
                $archiveIsTracked = Export::query()
                    ->whereKey($this->exportId)
                    ->where('disk', $result['disk'])
                    ->where('path', $result['path'])
                    ->exists();
            } catch (Throwable $exception) {
                report($exception);
            }

            if (! $archiveIsTracked) {
                $this->discardProvisionalArchive($result, $storedFileCleanup);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Export::query()->whereKey($this->exportId)->update([
            'status' => ExportStatus::Failed->value,
            'failed_at' => now(),
            'error_message' => 'The export could not be generated. Please retry later.',
        ]);
    }

    /**
     * @param  array{disk: string, path: string, filename: string, size: int}  $result
     */
    private function discardProvisionalArchive(array $result, StoredFileCleanup $storedFileCleanup): void
    {
        $deletionScheduled = false;

        try {
            $storedFileCleanup->schedule(
                $result['disk'],
                $result['path'],
                'user_export_generation_aborted',
            );
            $deletionScheduled = true;
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            if (Storage::disk($result['disk'])->delete($result['path'])) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! $deletionScheduled) {
            throw new RuntimeException('Unable to remove or schedule removal of a provisional export archive.');
        }
    }
}
