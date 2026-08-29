<?php

namespace App\Filament\App\Resources\EntryResource\RelationManagers;

use App\Actions\RestoreEntryVersion;
use App\Models\Entry;
use App\Models\EntryVersion;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Version history';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Entry
            && $ownerRecord->isOwnedBy($user)
            && $user->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Version history'))
            ->description(__('Earlier private versions are shown without exposing their contents in lists.'))
            ->columns([
                TextColumn::make('version')
                    ->label(__('Version'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual_save' => __('Manual save'),
                        'autosave' => __('Autosave'),
                        default => str($state ?: __('Automatic save'))->replace('_', ' ')->headline()->toString(),
                    })
                    ->placeholder(__('Automatic save')),
                TextColumn::make('created_at')
                    ->label(__('Saved'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                Action::make('inspect')
                    ->label(__('Inspect'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->authorize('view')
                    ->slideOver()
                    ->modalHeading(fn (EntryVersion $record): string => __('Memory version :version', ['version' => $record->version]))
                    ->modalDescription(__('This is a read-only snapshot. Inspect it before choosing whether to restore it.'))
                    ->modalContent(fn (EntryVersion $record) => view('filament.app.modals.entry-version', [
                        'entry' => $this->getOwnerRecord(),
                        'version' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                Action::make('restore')
                    ->label(__('Restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->authorize('restore')
                    ->requiresConfirmation()
                    ->modalHeading(fn (EntryVersion $record): string => __('Restore version :version?', ['version' => $record->version]))
                    ->modalDescription(__('The current memory is preserved in history. Restoring creates a new revision; it never deletes later versions.'))
                    ->modalSubmitActionLabel(__('Restore as a new revision'))
                    ->action(function (EntryVersion $record): void {
                        $user = Filament::auth()->user();
                        $entry = $this->getOwnerRecord();
                        abort_unless($user instanceof User && $entry instanceof Entry, 403);

                        app(RestoreEntryVersion::class)->handle($entry, $record, $user);

                        Notification::make()
                            ->success()
                            ->title(__('Memory version restored'))
                            ->body(__('The restored content is now the current memory, and the complete revision history remains available.'))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No earlier versions'))
            ->emptyStateDescription(__('Version history will appear as this memory changes.'))
            ->emptyStateIcon(Heroicon::OutlinedClock);
    }
}
