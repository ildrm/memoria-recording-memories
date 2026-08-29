<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReportStatus: string
{
    use HasOptions;

    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
