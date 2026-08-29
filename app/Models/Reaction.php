<?php

namespace App\Models;

use App\Enums\ReactionType;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'type',
    ];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ReactionType::class,
        ];
    }
}
