<?php

namespace App\Actions;

use App\Enums\ExportStatus;
use App\Jobs\GenerateUserExport;
use App\Models\Export;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\DB;

class RequestUserExport
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  array<int, string>  $formats
     */
    public function handle(
        User $owner,
        array $formats = ['json', 'markdown'],
        bool $includeAttachments = true,
    ): Export {
        return DB::transaction(function () use ($owner, $formats, $includeAttachments): Export {
            $export = new Export;
            $export->forceFill([
                'user_id' => $owner->getKey(),
                'format' => 'zip',
                'status' => ExportStatus::Pending,
                'options' => [
                    'formats' => array_values(array_unique($formats)),
                    'include_attachments' => $includeAttachments,
                ],
                'requested_at' => now(),
            ]);
            $export->save();

            $this->auditRecorder->record(
                event: 'export.requested',
                actor: $owner,
                auditable: $export,
                metadata: ['include_attachments' => $includeAttachments],
            );

            GenerateUserExport::dispatch((int) $export->getKey())->afterCommit();

            return $export;
        });
    }
}
