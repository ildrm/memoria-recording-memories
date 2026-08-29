<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PublicationTargetStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
