<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Your memories';

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 3,
        ];
    }
}
