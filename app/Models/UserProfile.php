<?php

namespace App\Models;

use Database\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'username',
        'display_name',
        'avatar_path',
        'avatar_disk',
        'biography',
        'cover_image_path',
        'cover_image_disk',
        'website_url',
        'is_public',
    ];

    protected static function booted(): void
    {
        static::saving(function (UserProfile $profile): void {
            if ($profile->username !== null) {
                $profile->username = Str::lower(trim($profile->username));
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }
}
