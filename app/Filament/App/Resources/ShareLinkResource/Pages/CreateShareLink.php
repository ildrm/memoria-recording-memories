<?php

namespace App\Filament\App\Resources\ShareLinkResource\Pages;

use App\Actions\CreateShareLink as CreateShareLinkAction;
use App\Filament\App\Resources\ShareLinkResource;
use App\Models\Entry;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateShareLink extends CreateRecord
{
    protected static string $resource = ShareLinkResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);
        app(InteractiveActionRateLimiter::class)->shareAction($user);

        $entry = Entry::query()->ownedBy($user)->findOrFail($data['entry_id']);
        $created = app(CreateShareLinkAction::class)->handle(
            entry: $entry,
            owner: $user,
            label: $data['label'] ?? null,
            password: $data['password'] ?? null,
            expiresAt: filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at']) : null,
            maxViews: filled($data['max_views'] ?? null) ? (int) $data['max_views'] : null,
            trackViews: (bool) ($data['track_views'] ?? false),
            includeAttachments: (bool) ($data['include_attachments'] ?? false),
        );

        session()->flash('created_share_url', $created->url);

        return $created->shareLink;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
