<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\OwnedByUser;
use Carbon\CarbonImmutable;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $expires_at
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $fillable = [
        'format',
        'status',
        'options',
        'disk',
        'path',
        'filename',
        'size_bytes',
        'requested_at',
        'started_at',
        'completed_at',
        'expires_at',
        'failed_at',
        'error_message',
    ];

    protected $hidden = [
        'disk',
        'path',
        'error_message',
    ];

    protected $attributes = [
        'format' => 'zip',
        'status' => ExportStatus::Pending->value,
    ];

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'options' => 'array',
            'size_bytes' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
