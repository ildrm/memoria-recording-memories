<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PublicationStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
