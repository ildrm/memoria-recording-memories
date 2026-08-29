<?php

namespace App\Filament\App\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Timeline extends MemoryCollectionPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?string $navigationLabel = 'Timeline';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Your timeline';

    protected string $view = 'filament.app.pages.timeline';

    public function emptyHeading(): string
    {
        return __('Your timeline is waiting');
    }

    public function emptyDescription(): string
    {
        return __('Write your first memory and it will find its place here.');
    }
}
