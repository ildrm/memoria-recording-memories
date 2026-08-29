<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'name',
        'color',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->normalized_name = Str::lower(trim($tag->name));
        });
    }

    /** @return BelongsToMany<Entry, $this, EntryTag, 'pivot'> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class)
            ->using(EntryTag::class)
            ->withPivot('attached_at');
    }
}
