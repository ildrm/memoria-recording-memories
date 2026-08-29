<?php

namespace App\Filament\App\Resources\JournalResource\Pages;

use App\Filament\App\Resources\Concerns\CreatesOwnedRecord;
use App\Filament\App\Resources\JournalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournal extends CreateRecord
{
    use CreatesOwnedRecord;

    protected static string $resource = JournalResource::class;

    protected static bool $canCreateAnother = false;
}
