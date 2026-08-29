<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'display_name',
        'nickname',
        'notes',
        'avatar_path',
        'relationship',
    ];

    /** @return BelongsToMany<Entry, $this, EntryPerson, 'pivot'> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class)
            ->using(EntryPerson::class)
            ->withPivot(['relationship_context', 'attached_at']);
    }
}
