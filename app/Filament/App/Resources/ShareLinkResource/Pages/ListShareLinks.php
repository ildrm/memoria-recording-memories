<?php

namespace App\Filament\App\Resources\ShareLinkResource\Pages;

use App\Filament\App\Resources\ShareLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShareLinks extends ListRecords
{
    protected static string $resource = ShareLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Create private link'))];
    }
}
