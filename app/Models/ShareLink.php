<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Carbon\CarbonImmutable;
use Database\Factories\ShareLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
class ShareLink extends Model
{
    /** @use HasFactory<ShareLinkFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'label',
        'include_attachments',
        'track_views',
        'view_count',
        'max_views',
        'last_accessed_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'password_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShareLink $shareLink): void {
            if (($shareLink->entry_id === null) === ($shareLink->publication_id === null)) {
                throw new LogicException('A share link must reference exactly one entry or publication.');
            }
        });
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_views === null || $this->view_count < $this->max_views);
    }

    public function matchesToken(string $plainTextToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $plainTextToken));
    }

    /**
     * @param  Builder<ShareLink>  $query
     * @return Builder<ShareLink>
     */
    #[Scope]
    protected function usable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('max_views')->orWhereColumn('view_count', '<', 'max_views');
            });
    }

    protected function casts(): array
    {
        return [
            'include_attachments' => 'boolean',
            'track_views' => 'boolean',
            'view_count' => 'integer',
            'max_views' => 'integer',
            'last_accessed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
