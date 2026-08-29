<?php

namespace App\Services;

use App\Models\Publication;
use App\Models\PublicationMedia;
use JsonException;

class PublicationWorkflowFingerprint
{
    /** @throws JsonException */
    public function forPublication(Publication $publication): string
    {
        $media = PublicationMedia::query()
            ->whereBelongsTo($publication)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'disk',
                'path',
                'mime_type',
                'size_bytes',
                'alt_text',
                'sort_order',
                'is_featured',
                'metadata_stripped',
                'metadata',
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

        $payload = json_encode([
            'publication_id' => (int) $publication->getKey(),
            'owner_user_id' => (int) $publication->user_id,
            'revision' => (int) $publication->revision,
            'source_revision' => $publication->source_revision === null
                ? null
                : (int) $publication->source_revision,
            'title' => (string) $publication->title,
            'slug' => (string) $publication->slug,
            'excerpt' => $publication->excerpt,
            'body' => (string) $publication->body,
            'topics' => is_array($publication->topics) ? array_values($publication->topics) : [],
            'settings' => [
                'comments_enabled' => (bool) $publication->comments_enabled,
                'reactions_enabled' => (bool) $publication->reactions_enabled,
                'search_engine_indexing' => (bool) $publication->search_engine_indexing,
            ],
            'media' => $media,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
