<?php

namespace Tests\Unit;

use App\Enums\AttachmentScanStatus;
use App\Services\AttachmentScanning\AttachmentScanResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AttachmentScanResultTest extends TestCase
{
    public function test_completed_scan_results_cannot_remain_pending(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AttachmentScanResult(AttachmentScanStatus::Pending, 'scanner');
    }

    public function test_completed_scan_result_preserves_sanitized_classification(): void
    {
        $result = new AttachmentScanResult(
            AttachmentScanStatus::Rejected,
            'clamav',
            'malware_detected',
        );

        $this->assertSame(AttachmentScanStatus::Rejected, $result->status);
        $this->assertSame('clamav', $result->scanner);
        $this->assertSame('malware_detected', $result->reasonCode);
    }
}
