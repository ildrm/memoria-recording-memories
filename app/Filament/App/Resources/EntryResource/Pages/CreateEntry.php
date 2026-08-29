<?php

namespace App\Filament\App\Resources\EntryResource\Pages;

use App\Actions\SaveEntry;
use App\Filament\App\Resources\EntryResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateEntry extends CreateRecord
{
    protected static string $resource = EntryResource::class;

    protected static bool $canCreateAnother = false;

    public string $autosaveState = 'saved';

    private bool $isAutosaving = false;

    public function autosave(): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->autosaveState = 'saving';
        $this->isAutosaving = true;

        try {
            $this->create();
            $this->autosaveState = 'saved';
        } catch (ValidationException) {
            $this->autosaveState = 'validation';
            $this->dispatch('entry-autosave-failed', kind: 'validation');
        } catch (\Throwable $exception) {
            $this->autosaveState = 'error';
            $this->dispatch('entry-autosave-failed', kind: 'server');
            report($exception);
        } finally {
            $this->isAutosaving = false;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->isAutosaving ? null : parent::getCreatedNotification();
    }

    protected function afterCreate(): void
    {
        $this->dispatch('entry-autosave-succeeded');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return app(SaveEntry::class)->handle(
            owner: $user,
            entry: null,
            attributes: $this->withAbsoluteDateTimes($data),
            autosave: $this->isAutosaving,
        );
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
