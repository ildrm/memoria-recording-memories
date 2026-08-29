<?php

namespace App\Filament\App\Resources\PublicationResource\Pages;

use App\Actions\CreateIndependentPublicationDraft;
use App\Filament\App\Resources\PublicationResource;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePublication extends CreateRecord
{
    protected static string $resource = PublicationResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);
        app(InteractiveActionRateLimiter::class)->publicationAction($user);

        return app(CreateIndependentPublicationDraft::class)->handle($user, $data);
    }
}
