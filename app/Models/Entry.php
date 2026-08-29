<?php

namespace App\Models;

use App\Enums\EntryStatus;
use App\Enums\Mood;
use App\Models\Concerns\OwnedByUser;
use Carbon\CarbonImmutable;
use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entry extends Model
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'journal_id',
        'title',
        'body',
        'occurred_at',
        'occurred_on',
        'timezone',
        'mood',
        'custom_mood',
        'location_name',
        'latitude',
        'longitude',
        'importance',
        'status',
        'is_favorite',
        'archived_at',
        'revision',
        'last_saved_at',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
        'importance' => 0,
        'status' => EntryStatus::Draft->value,
        'is_favorite' => false,
        'revision' => 1,
    ];

    protected static function booted(): void
    {
        static::saving(function (Entry $entry): void {
            if (! $entry->isDirty(['occurred_at', 'timezone'])) {
                return;
            }

            $occurredAt = $entry->getAttribute('occurred_at');
            $entry->occurred_on = $occurredAt instanceof CarbonImmutable
                ? $occurredAt->setTimezone($entry->timezone)->toDateString()
                : null;
        });
    }

    public function localOccurredAt(): ?CarbonImmutable
    {
        $occurredAt = $this->getAttribute('occurred_at');

        return $occurredAt instanceof CarbonImmutable
            ? $occurredAt->setTimezone($this->timezone)
            : null;
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return HasMany<EntryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EntryVersion::class)->orderByDesc('version');
    }

    /** @return BelongsToMany<Tag, $this, EntryTag, 'pivot'> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->using(EntryTag::class)
            ->withPivot('attached_at');
    }

    /** @return BelongsToMany<Person, $this, EntryPerson, 'pivot'> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class)
            ->using(EntryPerson::class)
            ->withPivot(['relationship_context', 'attached_at']);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<Publication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class, 'source_entry_id');
    }

    /** @return HasMany<EntryShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(EntryShare::class);
    }

    /** @return HasMany<ShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function isSharedWith(User $user): bool
    {
        return $this->shares()
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereBelongsTo($user, 'recipient')
            ->exists();
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function accessibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereBelongsTo($user, 'owner')
                ->orWhereHas('shares', fn (Builder $shares): Builder => $shares
                    ->whereNull('revoked_at')
                    ->where(function (Builder $query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->whereBelongsTo($user, 'recipient'));
        });
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function recent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function favorites(Builder $query): Builder
    {
        return $query->where('is_favorite', true);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function archived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function drafts(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Draft);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Active)->whereNull('archived_at');
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'occurred_on' => 'immutable_date',
            'mood' => Mood::class,
            'importance' => 'integer',
            'status' => EntryStatus::class,
            'is_favorite' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'revision' => 'integer',
            'last_saved_at' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
