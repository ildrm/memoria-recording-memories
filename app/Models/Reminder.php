<?php

namespace App\Models;

use App\Enums\ReminderFrequency;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'name',
        'frequency',
        'local_time',
        'day_of_week',
        'day_of_month',
        'interval_days',
        'timezone',
        'channels',
        'is_enabled',
        'next_run_at',
        'last_sent_at',
    ];

    protected $attributes = [
        'frequency' => ReminderFrequency::Daily->value,
        'timezone' => 'UTC',
        'is_enabled' => true,
    ];

    /**
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    #[Scope]
    protected function due(Builder $query): Builder
    {
        return $query
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    protected function casts(): array
    {
        return [
            'frequency' => ReminderFrequency::class,
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'interval_days' => 'integer',
            'channels' => 'array',
            'is_enabled' => 'boolean',
            'next_run_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
        ];
    }
}
