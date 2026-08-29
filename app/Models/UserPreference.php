<?php

namespace App\Models;

use App\Enums\AppearancePreference;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'timezone',
        'appearance',
        'on_this_day_enabled',
        'notification_preferences',
        'privacy_preferences',
    ];

    protected $attributes = [
        'locale' => 'en',
        'timezone' => 'UTC',
        'appearance' => AppearancePreference::System->value,
        'on_this_day_enabled' => false,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'appearance' => AppearancePreference::class,
            'on_this_day_enabled' => 'boolean',
            'notification_preferences' => 'array',
            'privacy_preferences' => 'array',
        ];
    }
}
