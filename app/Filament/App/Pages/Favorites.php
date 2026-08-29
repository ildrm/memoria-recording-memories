<?php

namespace App\Filament\App\Pages;

use App\Models\Entry;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Favorites extends MemoryCollectionPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?int $navigationSort = 13;

    protected static ?string $title = 'Favorite memories';

    /** @return Builder<Entry> */
    protected function entriesQuery(): Builder
    {
        return parent::entriesQuery()
            ->where('is_favorite', true)
            ->whereNull('archived_at');
    }

    public function emptyHeading(): string
    {
        return __('No favorite memories yet');
    }

    public function emptyDescription(): string
    {
        return __('Mark a memory as a favorite to keep it close.');
    }
}
