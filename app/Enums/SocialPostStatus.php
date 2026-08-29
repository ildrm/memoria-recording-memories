<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SocialPostStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Published = 'published';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Disconnected = 'disconnected';
    case TokenExpired = 'token_expired';
    case DeletionPending = 'deletion_pending';
    case Deleted = 'deleted';
    case DeletionFailed = 'deletion_failed';
}
