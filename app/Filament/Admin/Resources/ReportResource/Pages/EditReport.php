<?php

namespace App\Filament\Admin\Resources\ReportResource\Pages;

use App\Actions\UpdateReportModeration;
use App\Enums\ReportStatus;
use App\Filament\Admin\Resources\ReportResource;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Report, 404);

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        $status = ReportStatus::tryFrom((string) ($data['status'] ?? ''));

        if (! $status instanceof ReportStatus) {
            throw ValidationException::withMessages([
                'status' => [__('Choose a valid report status.')],
            ]);
        }

        return app(UpdateReportModeration::class)->handle(
            report: $record,
            actor: $actor,
            status: $status,
            assigneeUserId: filled($data['assigned_to_user_id'] ?? null)
                ? (int) $data['assigned_to_user_id']
                : null,
            moderationNotes: is_string($data['moderation_notes'] ?? null) ? $data['moderation_notes'] : null,
            resolution: is_string($data['resolution'] ?? null) ? $data['resolution'] : null,
            request: request(),
        );
    }
}
