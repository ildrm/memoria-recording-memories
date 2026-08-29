<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SocialProvider: string
{
    use HasOptions;

    case X = 'x';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Mastodon = 'mastodon';
}
