<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SharePermission: string
{
    use HasOptions;

    case View = 'view';
}
