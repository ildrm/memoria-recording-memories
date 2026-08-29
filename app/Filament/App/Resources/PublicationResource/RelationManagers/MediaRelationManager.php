<?php

namespace App\Filament\App\Resources\PublicationResource\RelationManagers;

use App\Actions\CopyAttachmentToPublication;
use App\Actions\RemovePublicationMedia;
use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Public media';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPhoto;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Publication
            && $ownerRecord->isOwnedBy($user)
            && $user->can('update', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Public media'))
            ->description(__('Choose only clean images from the source memory. Memoria creates a separately re-encoded public copy and removes embedded metadata. Still inspect faces, documents, reflections, signs, and visible location clues. Any media change withdraws a live or scheduled version for a fresh review and preview.'))
            ->columns([
                TextColumn::make('sourceAttachment.download_name')
                    ->label(__('Source image'))
                    ->placeholder(__('Private source removed')),
                TextColumn::make('alt_text')
                    ->label(__('Alternative text'))
                    ->wrap()
                    ->placeholder(__('Missing')),
                IconColumn::make('is_featured')
                    ->label(__('Featured'))
                    ->boolean(),
                IconColumn::make('metadata_stripped')
                    ->label(__('Metadata removed'))
                    ->boolean(),
                TextColumn::make('size_bytes')
                    ->label(__('Public copy'))
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->description(fn (PublicationMedia $record): string => (string) $record->mime_type),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Action::make('copySafeImage')
                    ->label(__('Add a safe image'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->color('gray')
                    ->visible(fn (): bool => $this->publication()->source_entry_id !== null)
                    ->schema([
                        Select::make('attachment_id')
                            ->label(__('Clean image from the source memory'))
                            ->options(fn (): array => $this->eligibleAttachmentOptions())
                            ->searchable()
                            ->required()
                            ->helperText(__('Only owner-controlled image attachments with a completed clean scan are eligible.')),
                        TextInput::make('alt_text')
                            ->label(__('Alternative text'))
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('Briefly describe what the image communicates. Do not repeat the caption.')),
                        Toggle::make('featured')
                            ->label(__('Use as the featured image'))
                            ->default(fn (): bool => ! $this->publication()->media()->exists())
                            ->helperText(__('Only one image can be featured.')),
                    ])
                    ->modalHeading(__('Create a separate public image'))
                    ->modalDescription(__('The original private attachment stays private and unchanged. A sanitized derivative is created only after server-side validation.'))
                    ->modalSubmitActionLabel(__('Create public copy'))
                    ->action(function (array $data): void {
                        $this->throttlePublicationAction();
                        $publication = $this->publication();
                        $attachment = Attachment::query()
                            ->ownedBy($this->user())
                            ->where('entry_id', $publication->source_entry_id)
                            ->findOrFail((int) $data['attachment_id']);

                        app(CopyAttachmentToPublication::class)->handle(
                            attachment: $attachment,
                            publication: $publication,
                            owner: $this->user(),
                            altText: $data['alt_text'],
                            featured: (bool) ($data['featured'] ?? false),
                        );

                        Notification::make()
                            ->success()
                            ->title(__('Public image created'))
                            ->body(__('Review the sanitized image in the exact public preview before publishing.'))
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (PublicationMedia $record): string => route('publications.media.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('editPresentation')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->authorize('update')
                    ->fillForm(fn (PublicationMedia $record): array => [
                        'alt_text' => $record->alt_text,
                        'featured' => $record->is_featured,
                    ])
                    ->schema([
                        TextInput::make('alt_text')
                            ->label(__('Alternative text'))
                            ->required()
                            ->maxLength(255),
                        Toggle::make('featured')
                            ->label(__('Use as the featured image')),
                    ])
                    ->action(function (array $data, PublicationMedia $record): void {
                        $this->throttlePublicationAction();
                        $attachment = $record->sourceAttachment;
                        abort_unless($attachment instanceof Attachment, 422);

                        app(CopyAttachmentToPublication::class)->handle(
                            attachment: $attachment,
                            publication: $this->publication(),
                            owner: $this->user(),
                            altText: $data['alt_text'],
                            featured: (bool) ($data['featured'] ?? false),
                        );
                    }),
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalDescription(__('Only the sanitized public copy is removed. The private source attachment stays in the memory.'))
                    ->action(function (PublicationMedia $record): void {
                        $this->throttlePublicationAction();
                        app(RemovePublicationMedia::class)->handle($record, $this->user());
                    }),
            ])
            ->emptyStateHeading(__('No public images'))
            ->emptyStateDescription($this->publication()->source_entry_id === null
                ? __('This publication has no source memory, so there are no private attachments to copy.')
                : __('Add a clean source image when it is appropriate for the public audience.'))
            ->emptyStateIcon(Heroicon::OutlinedPhoto);
    }

    /** @return array<int|string, string> */
    private function eligibleAttachmentOptions(): array
    {
        $publication = $this->publication();

        if ($publication->source_entry_id === null) {
            return [];
        }

        return Attachment::query()
            ->ownedBy($this->user())
            ->where('entry_id', $publication->source_entry_id)
            ->where('scan_status', AttachmentScanStatus::Clean)
            ->where('media_type', AttachmentMediaType::Image)
            ->whereDoesntHave('publicationMedia', fn ($query) => $query
                ->where('publication_id', $publication->getKey()))
            ->orderBy('download_name')
            ->get(['id', 'download_name', 'size_bytes'])
            ->mapWithKeys(fn (Attachment $attachment): array => [
                $attachment->getKey() => $attachment->download_name.' · '.Number::fileSize((int) $attachment->size_bytes),
            ])
            ->all();
    }

    private function publication(): Publication
    {
        $publication = $this->getOwnerRecord();
        abort_unless($publication instanceof Publication && $publication->isOwnedBy($this->user()), 403);

        return $publication;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function throttlePublicationAction(): void
    {
        app(InteractiveActionRateLimiter::class)->publicationAction($this->user());
    }
}
