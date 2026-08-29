<?php

namespace App\Filament\App\Resources\EntryResource\Pages;

use App\Filament\App\Resources\EntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEntries extends ListRecords
{
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Write a memory'))
                ->icon('heroicon-o-pencil-square'),
        ];
    }
}
