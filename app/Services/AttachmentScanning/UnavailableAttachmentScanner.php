<?php

namespace App\Services\AttachmentScanning;

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;

class UnavailableAttachmentScanner implements AttachmentScanner
{
    public function scan(Attachment $attachment): AttachmentScanResult
    {
        return new AttachmentScanResult(
            AttachmentScanStatus::Failed,
            'unavailable',
            'scanner_unavailable',
        );
    }
}
