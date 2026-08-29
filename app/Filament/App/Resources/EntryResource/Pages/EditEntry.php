<?php

namespace App\Filament\App\Resources\EntryResource\Pages;

use App\Actions\CreateEntryShare;
use App\Actions\CreatePublicationDraft;
use App\Actions\SaveEntry;
use App\Filament\App\Resources\EntryResource;
use App\Filament\App\Resources\PublicationResource;
use App\Models\Entry;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditEntry extends EditRecord
{
    protected static string $resource = EntryResource::class;

    public string $autosaveState = 'saved';

    private bool $isAutosaving = false;

    public function autosave(): void
    {
        $this->autosaveState = 'saving';
        $this->isAutosaving = true;

        try {
            $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            $this->refreshFormData(['revision', 'last_saved_at']);
            $this->autosaveState = 'saved';
        } catch (ValidationException $exception) {
            $isRevisionConflict = collect(array_keys($exception->errors()))
                ->contains(static fn (string $key): bool => $key === 'revision' || str_ends_with($key, '.revision'));

            if ($isRevisionConflict) {
                $this->autosaveState = 'conflict';
                $this->dispatch('entry-autosave-failed', kind: 'conflict');

                Notification::make()
                    ->warning()
                    ->title(__('A newer version was saved elsewhere'))
                    ->body(__('Reload this memory before editing so newer writing is not overwritten.'))
                    ->persistent()
                    ->send();
            } else {
                $this->autosaveState = 'validation';
                $this->dispatch('entry-autosave-failed', kind: 'validation');
            }
        } catch (\Throwable $exception) {
            $this->autosaveState = 'error';
            $this->dispatch('entry-autosave-failed', kind: 'server');
            report($exception);
        } finally {
            $this->isAutosaving = false;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('shareWithMember')
                ->label(__('Share view-only'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray')
                ->authorize('share')
                ->modalHeading(__('Share this memory with a registered member'))
                ->modalDescription(__('They receive view-only access after signing in. They cannot edit, publish, reshare, or search inside this memory.'))
                ->schema([
                    TextInput::make('recipient_email')
                        ->label(__('Member email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->autocomplete(false),
                    DateTimePicker::make('expires_at')
                        ->label(__('Access expires (optional)'))
                        ->native(false)
                        ->seconds(false)
                        ->minDate(now()->addMinute())
                        ->maxDate(now()->addDays((int) config('memoria.shares.maximum_expiration_days', 365)))
                        ->default(now()->addDays((int) config('memoria.shares.default_expiration_days', 30)))
                        ->helperText(__('A default expiry protects forgotten access. Clear it only for deliberate access without an expiry.')),
                    Toggle::make('include_attachments')
                        ->label(__('Include attachments that passed the safety check'))
                        ->helperText(__('Pending, failed, or rejected files are never shared.'))
                        ->default(false),
                ])
                ->modalSubmitActionLabel(__('Grant view-only access'))
                ->action(function (array $data, Entry $record): void {
                    app(InteractiveActionRateLimiter::class)->shareAction($this->user());

                    $recipient = User::query()
                        ->where('email', $data['recipient_email'])
                        ->whereNull('disabled_at')
                        ->first();

                    if (! $recipient instanceof User) {
                        throw ValidationException::withMessages([
                            'recipient_email' => [__('No active account can receive this memory at that address.')],
                        ]);
                    }

                    app(CreateEntryShare::class)->handle(
                        entry: $record,
                        owner: $this->user(),
                        recipient: $recipient,
                        expiresAt: filled($data['expires_at'] ?? null)
                            ? CarbonImmutable::parse($data['expires_at'])
                            : null,
                        includeAttachments: (bool) ($data['include_attachments'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('View-only access granted'))
                        ->body(__('You can revoke access at any time from the Registered access tab below.'))
                        ->send();
                }),
            Action::make('createPublication')
                ->label(__('Create public version'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->authorize('update')
                ->requiresConfirmation()
                ->modalHeading(__('Create a separate public version?'))
                ->modalDescription(__('A publication draft will copy this memory. Your original entry stays private, and the draft is not public until you complete the privacy review and publish explicitly.'))
                ->modalSubmitActionLabel(__('Create publication draft'))
                ->action(function (Entry $record): void {
                    $user = Filament::auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(InteractiveActionRateLimiter::class)->publicationAction($user);

                    $publication = app(CreatePublicationDraft::class)->handle($record, $user);
                    $this->redirect(PublicationResource::getUrl('edit', ['record' => $publication]));
                }),
            DeleteAction::make()->label(__('Move to trash')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && $record instanceof Entry, 403);

        $savedEntry = app(SaveEntry::class)->handle(
            owner: $user,
            entry: $record,
            attributes: $this->withAbsoluteDateTimes($data),
            expectedRevision: (int) ($data['revision'] ?? $record->revision),
            autosave: $this->isAutosaving,
        );

        $record->setRawAttributes($savedEntry->getAttributes(), true);

        return $record;
    }

    protected function afterSave(): void
    {
        $this->dispatch('entry-autosave-succeeded');
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Filament's timezone-aware picker dehydrates its value in the application timezone.
     * The domain action treats DateTimeInterface values as already-resolved instants.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withAbsoluteDateTimes(array $data): array
    {
        if (is_string($data['occurred_at'] ?? null) && filled($data['occurred_at'])) {
            $data['occurred_at'] = CarbonImmutable::parse(
                $data['occurred_at'],
                (string) config('app.timezone', 'UTC'),
            );
        }

        return $data;
    }
}
