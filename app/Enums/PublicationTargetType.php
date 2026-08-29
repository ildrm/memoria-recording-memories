<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PublicationTargetType: string
{
    use HasOptions;

    case Website = 'website';
    case Social = 'social';
}
