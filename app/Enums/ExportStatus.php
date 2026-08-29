<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ExportStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
