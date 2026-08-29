<?php

namespace App\Filament\App\Resources;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

abstract class OwnedResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return parent::getEloquentQuery()->whereBelongsTo($user, 'owner');
    }
}
