<?php

namespace App\Contracts;

use App\Models\Attachment;
use App\Services\AttachmentScanning\AttachmentScanResult;

interface AttachmentScanner
{
    public function scan(Attachment $attachment): AttachmentScanResult;
}
