<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EntryPerson extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'entry_person';

    protected function casts(): array
    {
        return [
            'attached_at' => 'immutable_datetime',
        ];
    }
}
