<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RoleName: string
{
    use HasOptions;

    case User = 'user';
    case Moderator = 'moderator';
    case Administrator = 'admin';
    case SuperAdministrator = 'super-admin';
}
