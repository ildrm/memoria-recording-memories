<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EntryStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Active = 'active';
}
