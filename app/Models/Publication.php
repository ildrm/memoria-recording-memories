<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Models\Concerns\OwnedByUser;
use Carbon\CarbonImmutable;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CarbonImmutable|null $published_at
 */
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'topics',
        'status',
        'comments_enabled',
        'reactions_enabled',
        'search_engine_indexing',
        'privacy_reviewed_at',
        'scheduled_at',
        'published_at',
        'unpublished_at',
        'archived_at',
        'source_revision',
        'revision',
    ];

    protected $attributes = [
        'status' => PublicationStatus::Draft->value,
        'comments_enabled' => false,
        'reactions_enabled' => false,
        'search_engine_indexing' => false,
        'topics' => '[]',
        'revision' => 1,
    ];

    /** @return BelongsTo<Entry, $this> */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'source_entry_id')->withTrashed();
    }

    /** @return HasMany<PublicationMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(PublicationMedia::class)->orderBy('sort_order');
    }

    /** @return HasOne<PublicationMedia, $this> */
    public function featuredMedia(): HasOne
    {
        return $this->hasOne(PublicationMedia::class)->where('is_featured', true);
    }

    /** @return HasMany<PublicationVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PublicationVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<PublicationTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PublicationTarget::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return HasMany<ShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === PublicationStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast()
            && $this->owner()->whereNull('disabled_at')->exists()
            && $this->targets()
                ->where('type', PublicationTargetType::Website)
                ->where('status', PublicationTargetStatus::Published)
                ->where('user_id', $this->user_id)
                ->exists();
    }

    /**
     * @param  Builder<Publication>  $query
     * @return Builder<Publication>
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query
            ->where('status', PublicationStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<Publication>  $query
     * @return Builder<Publication>
     */
    #[Scope]
    protected function websitePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PublicationStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereHas('owner', fn ($owner) => $owner->whereNull('disabled_at'))
            ->whereHas('targets', fn ($targets) => $targets
                ->where('type', PublicationTargetType::Website)
                ->where('status', PublicationTargetStatus::Published)
                ->whereColumn('publication_targets.user_id', 'publications.user_id'));
    }

    /**
     * @param  Builder<Publication>  $query
     * @return Builder<Publication>
     */
    #[Scope]
    protected function scheduled(Builder $query): Builder
    {
        return $query
            ->where('status', PublicationStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->whereHas('owner', fn (Builder $owner): Builder => $owner->whereNull('disabled_at'));
    }

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'topics' => 'array',
            'comments_enabled' => 'boolean',
            'reactions_enabled' => 'boolean',
            'search_engine_indexing' => 'boolean',
            'privacy_reviewed_at' => 'immutable_datetime',
            'scheduled_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'source_revision' => 'integer',
            'revision' => 'integer',
        ];
    }
}
