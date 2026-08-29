<?php

namespace App\Filament\App\Resources\ReminderResource\Pages;

use App\Filament\App\Resources\ReminderResource;
use App\Services\ReminderSchedule;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReminder extends EditRecord
{
    protected static string $resource = ReminderResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(ReminderSchedule::class)->prepareFormDataForFill($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(ReminderSchedule::class)->prepareFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
