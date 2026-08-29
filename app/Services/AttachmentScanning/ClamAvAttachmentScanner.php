<?php

namespace App\Services\AttachmentScanning;

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class ClamAvAttachmentScanner implements AttachmentScanner
{
    public function scan(Attachment $attachment): AttachmentScanResult
    {
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        if ($stream === false) {
            throw new RuntimeException('The attachment could not be opened for scanning.');
        }

        $process = new Process([
            (string) config('memoria.attachments.scanner.binary', 'clamscan'),
            '--no-summary',
            '--stdout',
            '-',
        ]);
        $process->setTimeout(max(
            5,
            (int) config('memoria.attachments.scanner.timeout_seconds', 60),
        ));
        $process->setInput($stream);

        try {
            $exitCode = $process->run();
        } finally {
            fclose($stream);
        }

        return match ($exitCode) {
            0 => new AttachmentScanResult(AttachmentScanStatus::Clean, 'clamav'),
            1 => new AttachmentScanResult(
                AttachmentScanStatus::Rejected,
                'clamav',
                'malware_detected',
            ),
            default => throw new RuntimeException('The malware scanner could not complete the scan.'),
        };
    }
}
