<?php

namespace App\Filament\App\Resources\TagResource\Pages;

use App\Filament\App\Resources\Concerns\CreatesOwnedRecord;
use App\Filament\App\Resources\TagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    use CreatesOwnedRecord;

    protected static string $resource = TagResource::class;

    protected static bool $canCreateAnother = true;
}
