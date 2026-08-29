<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReactionType: string
{
    use HasOptions;

    case Like = 'like';
    case Love = 'love';
    case Support = 'support';
    case Insightful = 'insightful';
}
