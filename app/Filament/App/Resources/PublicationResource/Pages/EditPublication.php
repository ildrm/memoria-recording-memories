<?php

namespace App\Filament\App\Resources\PublicationResource\Pages;

use App\Actions\ArchivePublication;
use App\Actions\CancelPublicationSchedule;
use App\Actions\ConfirmPublicationPrivacyReview;
use App\Actions\PublishPublication;
use App\Actions\RestoreArchivedPublication;
use App\Actions\SchedulePublication;
use App\Actions\UnpublishPublication;
use App\Actions\UpdatePublicationDraft;
use App\Enums\PublicationStatus;
use App\Filament\App\Resources\PublicationResource;
use App\Filament\App\Support\SocialAccountPresentation;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use App\Services\PublicationPrivacyReview;
use App\Services\PublicationWorkflowConfirmation;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPublication extends EditRecord
{
    protected static string $resource = PublicationResource::class;

    private bool $visibilityWasWithdrawn = false;

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return match ($record instanceof Publication ? $record->status : null) {
            PublicationStatus::Published => __('This version is public. Saving any story or audience change removes it from public view, cancels pending delivery, and requires a fresh privacy review and preview before republishing.'),
            PublicationStatus::Scheduled => __('This version is scheduled. Saving any story or audience change cancels the schedule and requires a fresh privacy review and preview.'),
            PublicationStatus::Archived => __('This public version is preserved in your archive. Restore it as a private draft before editing or publishing it again.'),
            default => __('Your private source memory is never changed by edits here.'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('privacyReview')
                ->label(__('Privacy review'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color(fn (Publication $record): string => $record->privacy_reviewed_at ? 'success' : 'warning')
                ->authorize('update')
                ->visible(fn (Publication $record): bool => $record->status !== PublicationStatus::Archived)
                ->modalHeading(__('Privacy gate · Step 1 of 2'))
                ->modalDescription(__('Review the exact public copy below. Automated prompts can help, but only you can decide whether every detail and image is appropriate to share.'))
                ->modalContent(function (Publication $record) {
                    $record->loadMissing('media');

                    return view('filament.app.modals.privacy-review', [
                        'publication' => $record,
                        'warnings' => app(PublicationPrivacyReview::class)->warnings($record),
                        'fingerprint' => app(PublicationWorkflowConfirmation::class)->currentFingerprint($record),
                    ]);
                })
                ->modalCancelActionLabel(__('Back to edit'))
                ->modalSubmitActionLabel(__('Confirm review and open exact preview'))
                ->action(function (Publication $record): void {
                    $this->throttlePublicationAction();
                    app(ConfirmPublicationPrivacyReview::class)->handle($record, $this->user());

                    Notification::make()
                        ->success()
                        ->title(__('Review recorded for this exact version'))
                        ->body(__('The story is still private. The exact preview is opening now; changing text, settings, or media will require both steps again.'))
                        ->send();

                    $this->redirect(route('app.publications.preview', $record));
                }),
            Action::make('preview')
                ->label(__('Preview public page'))
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->visible(fn (Publication $record): bool => $record->status !== PublicationStatus::Archived)
                ->disabled(fn (Publication $record): bool => $record->privacy_reviewed_at === null)
                ->tooltip(fn (Publication $record): string => $record->privacy_reviewed_at === null
                    ? __('Complete the privacy review for this version first.')
                    : __('Inspect the exact public page, then confirm it from the preview.'))
                ->url(fn (Publication $record): string => route('app.publications.preview', $record)),
            Action::make('publish')
                ->label(__('Publish'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('success')
                ->authorize('publish')
                ->visible(fn (Publication $record): bool => in_array($record->status, [PublicationStatus::Draft, PublicationStatus::Unpublished], true))
                ->disabled(fn (Publication $record): bool => ! $this->isReadyToPublish($record))
                ->tooltip(fn (Publication $record): ?string => $this->isReadyToPublish($record)
                    ? null
                    : __('Review privacy, then open the exact preview before publishing.'))
                ->schema([
                    Toggle::make('publishToWebsite')
                        ->label(__('Publish on my public profile'))
                        ->helperText(__('Turn this off for social-only delivery. Social-only publication does not create a public website page.'))
                        ->default(true),
                    CheckboxList::make('socialAccountIds')
                        ->label(__('Also publish to these exact accounts'))
                        ->options(fn (): array => $this->connectedAccountOptions())
                        ->helperText(__('Each choice names a specific connected identity. External posts may remain after you unpublish the local story. Empty or incomplete accounts are managed under Connected accounts.')),
                ])
                ->requiresConfirmation()
                ->modalHeading(__('Publish this public version?'))
                ->modalDescription(__('The server verified that the recorded privacy review and preview match this exact text, settings, and media. Choose at least one delivery target. Your source memory remains private.'))
                ->modalSubmitActionLabel(__('Publish publicly'))
                ->action(function (array $data, Publication $record): void {
                    $this->throttlePublicationAction();
                    $published = app(PublishPublication::class)->handle(
                        publication: $record,
                        owner: $this->user(),
                        privacyReviewConfirmed: true,
                        previewConfirmed: true,
                        publishToWebsite: (bool) ($data['publishToWebsite'] ?? true),
                        socialProviders: [],
                        socialAccountIds: $data['socialAccountIds'] ?? [],
                    );

                    $this->refreshFormData(['status', 'published_at', 'scheduled_at']);

                    Notification::make()
                        ->success()
                        ->title(($data['publishToWebsite'] ?? true)
                            ? __('Published on your public profile')
                            : __('Queued for social delivery only'))
                        ->body(($data['publishToWebsite'] ?? true)
                            ? trans_choice('The website version is now public; :count exact social account is queued.|The website version is now public; :count exact social accounts are queued.', count($data['socialAccountIds'] ?? []), ['count' => count($data['socialAccountIds'] ?? [])])
                            : __('No public website page was created. Connected-provider delivery can still be pending or fail.'))
                        ->send();
                }),
            Action::make('schedule')
                ->label(__('Schedule'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->authorize('publish')
                ->visible(fn (Publication $record): bool => in_array($record->status, [PublicationStatus::Draft, PublicationStatus::Unpublished], true))
                ->disabled(fn (Publication $record): bool => ! $this->isReadyToPublish($record))
                ->tooltip(fn (Publication $record): ?string => $this->isReadyToPublish($record)
                    ? null
                    : __('Review privacy, then open the exact preview before scheduling.'))
                ->schema([
                    Select::make('timezone')
                        ->label(__('Timezone'))
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                        ->searchable()
                        ->default(fn (): string => FilamentTimezone::get())
                        ->live()
                        ->required(),
                    DateTimePicker::make('scheduledAt')
                        ->label(__('Publish at'))
                        ->native(false)
                        ->seconds(false)
                        ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: FilamentTimezone::get()))
                        ->minDate(now()->addMinute())
                        ->required(),
                    Toggle::make('publishToWebsite')
                        ->label(__('Publish on my public profile'))
                        ->helperText(__('Turn this off for social-only delivery; no public website page will be created.'))
                        ->default(true),
                    CheckboxList::make('socialAccountIds')
                        ->label(__('Also publish to these exact accounts'))
                        ->options(fn (): array => $this->connectedAccountOptions())
                        ->helperText(__('Account identity is fixed when you schedule; no “latest” account is chosen later.')),
                ])
                ->action(function (array $data, Publication $record): void {
                    $this->throttlePublicationAction();
                    app(SchedulePublication::class)->handle(
                        publication: $record,
                        owner: $this->user(),
                        scheduledAt: CarbonImmutable::parse((string) $data['scheduledAt'], config('app.timezone', 'UTC')),
                        timezone: $data['timezone'],
                        privacyReviewConfirmed: true,
                        previewConfirmed: true,
                        publishToWebsite: (bool) ($data['publishToWebsite'] ?? true),
                        socialProviders: [],
                        socialAccountIds: $data['socialAccountIds'] ?? [],
                    );

                    $this->refreshFormData(['status', 'scheduled_at']);

                    Notification::make()
                        ->success()
                        ->title(($data['publishToWebsite'] ?? true)
                            ? __('Website publication scheduled')
                            : __('Social-only delivery scheduled'))
                        ->body(($data['publishToWebsite'] ?? true)
                            ? __('The exact reviewed version will appear on your public profile at the chosen time.')
                            : __('No public website page will be created at the scheduled time.'))
                        ->send();
                }),
            Action::make('cancelSchedule')
                ->label(__('Cancel schedule'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->authorize('publish')
                ->visible(fn (Publication $record): bool => $record->status === PublicationStatus::Scheduled)
                ->requiresConfirmation()
                ->action(function (Publication $record): void {
                    $this->throttlePublicationAction();
                    app(CancelPublicationSchedule::class)->handle($record, $this->user());
                    $this->refreshFormData(['status', 'scheduled_at']);
                }),
            Action::make('unpublish')
                ->label(__('Unpublish'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('danger')
                ->authorize('publish')
                ->visible(fn (Publication $record): bool => $record->status === PublicationStatus::Published)
                ->requiresConfirmation()
                ->modalDescription(__('Any active website page will stop working. Memoria will request asynchronous social-post removal where supported, but provider copies may remain if authorization or removal fails.'))
                ->action(function (Publication $record): void {
                    $this->throttlePublicationAction();
                    app(UnpublishPublication::class)->handle($record, $this->user());
                    $this->refreshFormData(['status', 'unpublished_at']);
                }),
            Action::make('archive')
                ->label(__('Archive'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->authorize('update')
                ->visible(fn (Publication $record): bool => $record->status !== PublicationStatus::Archived)
                ->requiresConfirmation()
                ->modalDescription(__('This removes local public or scheduled delivery and preserves the version in your archive. Memoria will request asynchronous social-post removal where supported, but provider copies may remain if authorization or removal fails.'))
                ->action(function (Publication $record): void {
                    $this->throttlePublicationAction();
                    app(ArchivePublication::class)->handle($record, $this->user());
                    $this->redirect(PublicationResource::getUrl('index'));
                }),
            Action::make('restore')
                ->label(__('Restore as draft'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->authorize('update')
                ->visible(fn (Publication $record): bool => $record->status === PublicationStatus::Archived)
                ->action(function (Publication $record): void {
                    $this->throttlePublicationAction();
                    app(RestoreArchivedPublication::class)->handle($record, $this->user());
                    $this->redirect(PublicationResource::getUrl('edit', ['record' => $record]));
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Publication, 404);
        $this->throttlePublicationAction();

        $previousStatus = $record->status;
        $updated = app(UpdatePublicationDraft::class)->handle(
            publication: $record,
            owner: $this->user(),
            attributes: $data,
        );

        $this->visibilityWasWithdrawn = in_array($previousStatus, [
            PublicationStatus::Published,
            PublicationStatus::Scheduled,
        ], true) && $updated->status !== $previousStatus;

        $record->setRawAttributes($updated->getAttributes(), true);

        return $record;
    }

    protected function getSavedNotification(): ?Notification
    {
        if (! $this->visibilityWasWithdrawn) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->warning()
            ->title(__('Saved and removed from public delivery'))
            ->body(__('Your edits are private. Complete a new privacy review, preview the exact version, and publish explicitly when it is ready.'))
            ->persistent();
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

    private function isReadyToPublish(Publication $publication): bool
    {
        try {
            app(PublicationWorkflowConfirmation::class)->assertReadyToPublish($publication);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function connectedAccountOptions(): array
    {
        return SocialAccount::query()
            ->ownedBy($this->user())
            ->whereNull('revoked_at')
            ->orderBy('provider')
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialAccount $account): bool => SocialAccountPresentation::isReadyForDelivery($account))
            ->mapWithKeys(fn (SocialAccount $account): array => [
                $account->getKey() => SocialAccountPresentation::label($account)
                    .(config('memoria.social.driver') === 'fake' ? ' · '.__('simulation') : ''),
            ])
            ->all();
    }
}
