<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EntryTag extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'entry_tag';

    protected function casts(): array
    {
        return [
            'attached_at' => 'immutable_datetime',
        ];
    }
}
