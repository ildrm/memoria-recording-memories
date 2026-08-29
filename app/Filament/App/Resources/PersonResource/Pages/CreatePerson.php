<?php

namespace App\Filament\App\Resources\PersonResource\Pages;

use App\Filament\App\Resources\Concerns\CreatesOwnedRecord;
use App\Filament\App\Resources\PersonResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePerson extends CreateRecord
{
    use CreatesOwnedRecord;

    protected static string $resource = PersonResource::class;
}
