<?php

namespace App\Services;

use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationVersion;

class PublicationSnapshotter
{
    public function snapshot(Publication $publication, string $reason): PublicationVersion
    {
        $nextRevision = max(
            (int) $publication->revision + 1,
            (int) $publication->versions()->max('version') + 1,
        );

        $publication->forceFill(['revision' => $nextRevision])->save();

        $media = PublicationMedia::query()
            ->whereBelongsTo($publication)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id', 'disk', 'path', 'mime_type', 'size_bytes', 'alt_text',
                'sort_order', 'is_featured', 'metadata_stripped', 'metadata',
            ])
            ->map(fn (PublicationMedia $medium): array => [
                'id' => (int) $medium->getKey(),
                'disk' => (string) $medium->disk,
                'path' => (string) $medium->path,
                'mime_type' => (string) $medium->mime_type,
                'size_bytes' => (int) $medium->size_bytes,
                'alt_text' => $medium->alt_text,
                'sort_order' => (int) $medium->sort_order,
                'is_featured' => (bool) $medium->is_featured,
                'metadata_stripped' => (bool) $medium->metadata_stripped,
                'metadata' => is_array($medium->metadata) ? $medium->metadata : [],
            ])
            ->all();

        $version = new PublicationVersion;
        $version->forceFill([
            'publication_id' => $publication->getKey(),
            'user_id' => $publication->user_id,
            'version' => $nextRevision,
            'title' => $publication->title,
            'excerpt' => $publication->excerpt,
            'body' => $publication->body,
            'status' => $publication->status,
            'settings' => [
                'comments_enabled' => (bool) $publication->comments_enabled,
                'reactions_enabled' => (bool) $publication->reactions_enabled,
                'search_engine_indexing' => (bool) $publication->search_engine_indexing,
                'topics' => is_array($publication->topics) ? array_values($publication->topics) : [],
                'media' => $media,
            ],
            'reason' => $reason,
        ]);
        $version->save();

        return $version;
    }
}
