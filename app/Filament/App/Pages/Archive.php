<?php

namespace App\Filament\App\Pages;

use App\Models\Entry;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Archive extends MemoryCollectionPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Organize';

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Archive';

    /** @return Builder<Entry> */
    protected function entriesQuery(): Builder
    {
        return Entry::query()
            ->ownedBy($this->user())
            ->whereNotNull('archived_at');
    }

    public function emptyHeading(): string
    {
        return __('Your archive is empty');
    }

    public function emptyDescription(): string
    {
        return __('Archive memories you want to keep without showing in everyday views.');
    }
}
