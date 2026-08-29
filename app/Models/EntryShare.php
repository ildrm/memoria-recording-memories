<?php

namespace App\Models;

use App\Enums\SharePermission;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\EntryShareFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryShare extends Model
{
    /** @use HasFactory<EntryShareFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'permission',
        'include_attachments',
        'expires_at',
        'revoked_at',
    ];

    protected $attributes = [
        'permission' => SharePermission::View->value,
        'include_attachments' => false,
    ];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    /**
     * @param  Builder<EntryShare>  $query
     * @return Builder<EntryShare>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    protected function ownerForeignKey(): string
    {
        return 'shared_by_user_id';
    }

    protected function casts(): array
    {
        return [
            'permission' => SharePermission::class,
            'include_attachments' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
