<?php

namespace App\Filament\App\Resources\ReminderResource\Pages;

use App\Filament\App\Resources\ReminderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReminders extends ListRecords
{
    protected static string $resource = ReminderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New reminder'))];
    }
}
