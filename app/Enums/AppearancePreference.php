<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AppearancePreference: string
{
    use HasOptions;

    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
