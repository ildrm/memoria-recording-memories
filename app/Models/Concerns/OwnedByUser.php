<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait OwnedByUser
{
    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, $this->ownerForeignKey());
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOwnedBy(Builder $query, User|int $owner): Builder
    {
        $ownerId = $owner instanceof User ? $owner->getKey() : $owner;

        return $query->where($this->qualifyColumn($this->ownerForeignKey()), $ownerId);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->getAttribute($this->ownerForeignKey()) === (int) $user->getKey();
    }

    protected function ownerForeignKey(): string
    {
        return 'user_id';
    }
}
