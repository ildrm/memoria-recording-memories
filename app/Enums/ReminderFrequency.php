<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReminderFrequency: string
{
    use HasOptions;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Custom = 'custom';
}
