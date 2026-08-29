<?php

namespace App\Filament\App\Resources\JournalResource\Pages;

use App\Filament\App\Resources\JournalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournals extends ListRecords
{
    protected static string $resource = JournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New journal')),
        ];
    }
}
