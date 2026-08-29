<?php

namespace App\Models;

use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use OwnedByUser;
    use SoftDeletes;

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'download_name',
        'mime_type',
        'extension',
        'size_bytes',
        'media_type',
        'sha256',
        'scan_status',
        'scanned_at',
        'metadata',
    ];

    protected $attributes = [
        'disk' => 'local',
        'scan_status' => AttachmentScanStatus::Pending->value,
    ];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return HasMany<PublicationMedia, $this> */
    public function publicationMedia(): HasMany
    {
        return $this->hasMany(PublicationMedia::class, 'source_attachment_id');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'media_type' => AttachmentMediaType::class,
            'scan_status' => AttachmentScanStatus::class,
            'scanned_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
