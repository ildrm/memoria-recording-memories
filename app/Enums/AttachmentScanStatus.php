<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AttachmentScanStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Clean = 'clean';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
