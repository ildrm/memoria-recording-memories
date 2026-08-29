<?php

namespace App\Models;

use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\SocialPostFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'provider',
        'status',
        'content',
        'remote_post_id',
        'remote_url',
        'attempt_count',
        'scheduled_at',
        'last_attempted_at',
        'next_retry_at',
        'published_at',
        'failed_at',
        'error_code',
        'error_message',
        'provider_metadata',
    ];

    protected $attributes = [
        'status' => SocialPostStatus::Pending->value,
        'attempt_count' => 0,
    ];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return BelongsTo<PublicationTarget, $this> */
    public function publicationTarget(): BelongsTo
    {
        return $this->belongsTo(PublicationTarget::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return HasMany<SocialPostFailure, $this> */
    public function failures(): HasMany
    {
        return $this->hasMany(SocialPostFailure::class)->orderByDesc('occurred_at');
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @return Builder<SocialPost>
     */
    #[Scope]
    protected function dispatchable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [SocialPostStatus::Pending, SocialPostStatus::Scheduled, SocialPostStatus::Retrying])
            ->where(function (Builder $query): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            });
    }

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'status' => SocialPostStatus::class,
            'attempt_count' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'provider_metadata' => 'array',
        ];
    }
}
