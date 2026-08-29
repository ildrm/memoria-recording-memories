<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'cover_path',
        'sort_order',
        'archived_at',
    ];

    protected $attributes = [
        'sort_order' => 0,
    ];

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * @param  Builder<Journal>  $query
     * @return Builder<Journal>
     */
    #[Scope]
    protected function archived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * @param  Builder<Journal>  $query
     * @return Builder<Journal>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
