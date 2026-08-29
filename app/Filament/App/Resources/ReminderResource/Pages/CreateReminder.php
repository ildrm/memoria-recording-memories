<?php

namespace App\Filament\App\Resources\ReminderResource\Pages;

use App\Filament\App\Resources\Concerns\CreatesOwnedRecord;
use App\Filament\App\Resources\ReminderResource;
use App\Services\ReminderSchedule;
use Filament\Resources\Pages\CreateRecord;

class CreateReminder extends CreateRecord
{
    use CreatesOwnedRecord;

    protected static string $resource = ReminderResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(ReminderSchedule::class)->prepareFormData($data);
    }
}
