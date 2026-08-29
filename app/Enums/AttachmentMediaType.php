<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AttachmentMediaType: string
{
    use HasOptions;

    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Other = 'other';
}
