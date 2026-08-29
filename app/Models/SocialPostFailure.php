<?php

namespace App\Models;

use Database\Factories\SocialPostFailureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostFailure extends Model
{
    /** @use HasFactory<SocialPostFailureFactory> */
    use HasFactory;

    protected $fillable = [
        'attempt',
        'error_class',
        'error_code',
        'message',
        'is_retryable',
        'context',
        'occurred_at',
    ];

    /** @return BelongsTo<SocialPost, $this> */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'is_retryable' => 'boolean',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
