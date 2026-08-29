<?php

namespace App\Services\AttachmentScanning;

use App\Enums\AttachmentScanStatus;
use InvalidArgumentException;

final readonly class AttachmentScanResult
{
    public function __construct(
        public AttachmentScanStatus $status,
        public string $scanner,
        public ?string $reasonCode = null,
    ) {
        if ($status === AttachmentScanStatus::Pending) {
            throw new InvalidArgumentException('A completed scan cannot remain pending.');
        }
    }
}
