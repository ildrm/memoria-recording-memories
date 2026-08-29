<?php

namespace App\Filament\App\Resources\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

trait CreatesOwnedRecord
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        $modelClass = static::getResource()::getModel();
        $record = new $modelClass;
        $record->fill($data);
        $record->owner()->associate($user);
        $record->save();

        return $record;
    }
}
