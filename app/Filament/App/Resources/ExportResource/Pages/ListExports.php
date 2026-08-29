<?php

namespace App\Filament\App\Resources\ExportResource\Pages;

use App\Actions\RequestUserExport;
use App\Filament\App\Resources\ExportResource;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExports extends ListRecords
{
    protected static string $resource = ExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestExport')
                ->label(__('Request export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    CheckboxList::make('formats')
                        ->label(__('Readable formats'))
                        ->options([
                            'json' => __('JSON data'),
                            'markdown' => __('Markdown documents'),
                        ])
                        ->default(['json', 'markdown'])
                        ->required(),
                    Toggle::make('includeAttachments')
                        ->label(__('Include private attachments'))
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $user = Filament::auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(InteractiveActionRateLimiter::class)->exportRequest($user);

                    app(RequestUserExport::class)->handle(
                        owner: $user,
                        formats: $data['formats'],
                        includeAttachments: (bool) ($data['includeAttachments'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Export requested'))
                        ->body(__('We will notify you when the secure archive is ready.'))
                        ->send();
                }),
        ];
    }
}
