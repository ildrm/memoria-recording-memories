<?php

namespace App\Models;

use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialProvider;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\PublicationTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationTarget extends Model
{
    /** @use HasFactory<PublicationTargetFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'target_key',
        'type',
        'provider',
        'status',
        'content_override',
        'settings',
        'scheduled_at',
        'dispatched_at',
        'completed_at',
        'failed_at',
    ];

    protected $attributes = [
        'type' => PublicationTargetType::Website->value,
        'status' => PublicationTargetStatus::Pending->value,
    ];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    protected function casts(): array
    {
        return [
            'type' => PublicationTargetType::class,
            'provider' => SocialProvider::class,
            'status' => PublicationTargetStatus::class,
            'settings' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
