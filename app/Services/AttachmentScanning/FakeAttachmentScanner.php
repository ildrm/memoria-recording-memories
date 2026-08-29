<?php

namespace App\Services\AttachmentScanning;

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class FakeAttachmentScanner implements AttachmentScanner
{
    private const EICAR_MARKER = 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE';

    public function scan(Attachment $attachment): AttachmentScanResult
    {
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        if ($stream === false) {
            return new AttachmentScanResult(
                AttachmentScanStatus::Failed,
                'fake',
                'source_unreadable',
            );
        }

        $tail = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    return new AttachmentScanResult(
                        AttachmentScanStatus::Failed,
                        'fake',
                        'source_unreadable',
                    );
                }

                $candidate = $tail.$chunk;
                if (str_contains($candidate, self::EICAR_MARKER)) {
                    return new AttachmentScanResult(
                        AttachmentScanStatus::Rejected,
                        'fake',
                        'malware_detected',
                    );
                }

                $tail = substr($candidate, -strlen(self::EICAR_MARKER));
            }
        } finally {
            fclose($stream);
        }

        return new AttachmentScanResult(AttachmentScanStatus::Clean, 'fake');
    }
}
