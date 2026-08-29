<?php

namespace App\Models;

use App\Enums\SocialProvider;
use App\Models\Concerns\OwnedByUser;
use Carbon\CarbonImmutable;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property SocialProvider $provider
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $token_expires_at
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'provider',
        'provider_user_id',
        'username',
        'display_name',
        'server_url',
        'token_expires_at',
        'scopes',
        'metadata',
        'connected_at',
        'last_refreshed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /** @return HasMany<PublicationTarget, $this> */
    public function publicationTargets(): HasMany
    {
        return $this->hasMany(PublicationTarget::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function isConnected(): bool
    {
        return $this->revoked_at === null
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'scopes' => 'array',
            'metadata' => 'array',
            'connected_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
