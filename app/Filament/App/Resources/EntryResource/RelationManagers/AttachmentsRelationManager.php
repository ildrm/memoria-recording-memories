<?php

namespace App\Filament\App\Resources\EntryResource\RelationManagers;

use App\Actions\DeleteAttachment;
use App\Actions\StoreAttachment;
use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Private files';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedPaperClip;

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
            ->heading(__('Private files'))
            ->description(__('Files listed here remain private and require authorization before download.'))
            ->headerActions([
                Action::make('upload')
                    ->label(__('Add private file'))
                    ->icon(Heroicon::OutlinedCloudArrowUp)
                    ->authorize(fn (): bool => $this->canUpload())
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('File'))
                            ->helperText(__('Stored privately. New uploads remain unavailable for download until the safety check permits them.'))
                            ->storeFiles(false)
                            ->previewable(false)
                            ->required()
                            ->rules([
                                File::types((array) config('memoria.attachments.extensions', []))
                                    ->max((int) config('memoria.attachments.maximum_kilobytes', 20480)),
                                'extensions:'.implode(',', (array) config('memoria.attachments.extensions', [])),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        $user = Filament::auth()->user();
                        $entry = $this->getOwnerRecord();
                        $file = $data['file'] ?? null;

                        abort_unless($user instanceof User && $entry instanceof Entry && $file instanceof TemporaryUploadedFile, 422);
                        app(InteractiveActionRateLimiter::class)->attachmentUpload($user);

                        app(StoreAttachment::class)->handle($file, $entry, $user);

                        Notification::make()
                            ->success()
                            ->title(__('Private file added'))
                            ->body(__('The safety check is pending. The file remains private.'))
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('original_name')
                    ->label(__('File'))
                    ->searchable()
                    ->icon(fn (Attachment $record): Heroicon => match ($record->media_type) {
                        AttachmentMediaType::Image => Heroicon::OutlinedPhoto,
                        AttachmentMediaType::Audio => Heroicon::OutlinedMusicalNote,
                        AttachmentMediaType::Video => Heroicon::OutlinedVideoCamera,
                        default => Heroicon::OutlinedDocument,
                    }),
                TextColumn::make('media_type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn (mixed $state): string => Str::headline($state instanceof \BackedEnum ? $state->value : (string) $state))
                    ->badge(),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                TextColumn::make('scan_status')
                    ->label(__('Safety check'))
                    ->formatStateUsing(fn (mixed $state): string => Str::headline($state instanceof \BackedEnum ? $state->value : (string) $state))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('Added'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize('download')
                    ->visible(fn (Attachment $record): bool => $record->scan_status === AttachmentScanStatus::Clean)
                    ->url(fn (Attachment $record): string => route('attachments.download', $record)),
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalHeading(__('Permanently delete this private file?'))
                    ->modalDescription(__('The private original cannot be recovered. Separately sanitized public copies are not deleted, but their link to this private source is removed. If a legacy public record still points to the private file itself, deletion is safely refused.'))
                    ->modalSubmitActionLabel(__('Delete private file'))
                    ->action(function (Attachment $record): void {
                        $user = Filament::auth()->user();
                        abort_unless($user instanceof User, 403);

                        try {
                            app(DeleteAttachment::class)->handle($record, $user);
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())->flatten()->first();

                            Notification::make()
                                ->danger()
                                ->title(__('This file could not be deleted safely'))
                                ->body(is_string($message) ? $message : __('Remove or replace the legacy public reference, then try again.'))
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Private file deleted'))
                            ->body(__('Any independent sanitized public copy remains unchanged and no longer references this private source.'))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No private files'))
            ->emptyStateDescription(__('Add a photo, recording, document, or video. Files stay private and require authorization to download.'))
            ->emptyStateIcon(Heroicon::OutlinedPaperClip);
    }

    private function canUpload(): bool
    {
        $user = Filament::auth()->user();
        $entry = $this->getOwnerRecord();

        return $user instanceof User
            && $entry instanceof Entry
            && $user->can('update', $entry)
            && $user->can('create', Attachment::class);
    }
}
