<?php

namespace App\Filament\App\Resources\ShareLinkResource\Pages;

use App\Actions\RevokeShareLink;
use App\Filament\App\Resources\ShareLinkResource;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditShareLink extends EditRecord
{
    protected static string $resource = ShareLinkResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && $record instanceof ShareLink, 403);
        app(InteractiveActionRateLimiter::class)->shareAction($user);

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label(__('Revoke link'))
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->authorize('delete')
                ->visible(fn (ShareLink $record): bool => $record->revoked_at === null)
                ->requiresConfirmation()
                ->modalDescription(__('Anyone using this link will immediately lose access. This cannot be undone.'))
                ->action(function (ShareLink $record): void {
                    $user = Filament::auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(InteractiveActionRateLimiter::class)->shareAction($user);
                    app(RevokeShareLink::class)->handle($record, $user);
                    $this->refreshFormData(['revoked_at']);
                }),
        ];
    }
}
