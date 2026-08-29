<?php

namespace App\Jobs;

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Services\AttachmentScanning\AttachmentScanResult;
use App\Services\AuditRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ScanAttachment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $attachmentId)
    {
        $this->onQueue('security');
    }

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('attachment-scan:'.$this->attachmentId))
                ->expireAfter(120)
                ->dontRelease(),
        ];
    }

    public function handle(
        AttachmentScanner $scanner,
        AuditRecorder $auditRecorder,
    ): void {
        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment === null || $attachment->scan_status !== AttachmentScanStatus::Pending) {
            return;
        }

        $sourceFingerprint = $this->sourceFingerprint($attachment);
        $result = $scanner->scan($attachment);

        DB::transaction(function () use (
            $attachment,
            $sourceFingerprint,
            $result,
            $auditRecorder,
        ): void {
            $attachment = Attachment::query()
                ->lockForUpdate()
                ->find($attachment->getKey());

            if ($attachment === null || $attachment->scan_status !== AttachmentScanStatus::Pending) {
                return;
            }

            if (! hash_equals($sourceFingerprint, $this->sourceFingerprint($attachment))) {
                throw new RuntimeException('The attachment changed while it was being scanned.');
            }

            $this->recordResult($attachment, $result, $auditRecorder);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment === null || $attachment->scan_status !== AttachmentScanStatus::Pending) {
            return;
        }

        $this->recordResult(
            $attachment,
            new AttachmentScanResult(
                AttachmentScanStatus::Failed,
                'configured',
                'scanner_error',
            ),
            app(AuditRecorder::class),
        );
    }

    private function recordResult(
        Attachment $attachment,
        AttachmentScanResult $result,
        AuditRecorder $auditRecorder,
    ): void {
        $attachment->forceFill([
            'scan_status' => $result->status,
            'scanned_at' => now(),
        ])->save();

        $auditRecorder->record(
            event: 'attachment.scan_completed',
            auditable: $attachment,
            metadata: [
                'attachment_id' => $attachment->getKey(),
                'status' => $result->status->value,
                'scanner' => $result->scanner,
                'reason_code' => $result->reasonCode,
            ],
        );
    }

    private function sourceFingerprint(Attachment $attachment): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                (string) $attachment->disk,
                (string) $attachment->path,
                (string) $attachment->sha256,
                (string) $attachment->size_bytes,
            ]),
            (string) config('app.key'),
        );
    }
}
