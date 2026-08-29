<?php

namespace App\Models;

use App\Enums\Mood;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\EntryVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryVersion extends Model
{
    /** @use HasFactory<EntryVersionFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'version',
        'title',
        'body',
        'occurred_at',
        'timezone',
        'mood',
        'custom_mood',
        'location_name',
        'latitude',
        'longitude',
        'importance',
        'metadata',
        'reason',
    ];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'mood' => Mood::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'importance' => 'integer',
            'metadata' => 'array',
        ];
    }
}
