<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $fillable = [
        'reason',
        'details',
        'status',
        'resolution',
        'moderation_notes',
        'resolved_at',
    ];

    protected $attributes = [
        'status' => ReportStatus::Open->value,
    ];

    protected static function booted(): void
    {
        static::saving(function (Report $report): void {
            if (($report->publication_id === null) === ($report->comment_id === null)) {
                throw new LogicException('A report must reference exactly one public publication or comment.');
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
