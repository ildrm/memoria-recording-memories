<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\PublicationVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationVersion extends Model
{
    /** @use HasFactory<PublicationVersionFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'version',
        'title',
        'excerpt',
        'body',
        'status',
        'settings',
        'reason',
    ];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => PublicationStatus::class,
            'settings' => 'array',
        ];
    }
}
