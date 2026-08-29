<?php

namespace App\Actions;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\EligibleReportAssignees;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateReportModeration
{
    public function __construct(
        private readonly EligibleReportAssignees $eligibleAssignees,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(
        Report $report,
        User $actor,
        ReportStatus $status,
        ?int $assigneeUserId,
        ?string $moderationNotes,
        ?string $resolution,
        ?Request $request = null,
    ): Report {
        Gate::forUser($actor)->authorize('update', $report);

        $validated = Validator::make([
            'moderation_notes' => $moderationNotes,
            'resolution' => $resolution,
        ], [
            'moderation_notes' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $assigneeUserId, $report, $request, $status, $validated): Report {
            $lockedReport = Report::query()
                ->lockForUpdate()
                ->findOrFail($report->getKey());

            Gate::forUser($actor)->authorize('update', $lockedReport);

            if ((int) $lockedReport->assigned_to_user_id !== (int) $assigneeUserId) {
                Gate::forUser($actor)->authorize('assign', $lockedReport);
            }

            $assignee = $assigneeUserId === null
                ? null
                : $this->eligibleAssignees->find($assigneeUserId);

            if ($assigneeUserId !== null && ! $assignee instanceof User) {
                throw ValidationException::withMessages([
                    'assigned_to_user_id' => [__('Choose an active moderator or administrator.')],
                ]);
            }

            $previousStatus = $lockedReport->status;
            $previousAssigneeUserId = $lockedReport->assigned_to_user_id;
            $isResolved = in_array($status, [ReportStatus::Resolved, ReportStatus::Dismissed], true);

            $lockedReport->forceFill([
                'status' => $status,
                'assigned_to_user_id' => $assignee?->getKey(),
                'moderation_notes' => $this->normalizeText($validated['moderation_notes'] ?? null),
                'resolution' => $this->normalizeText($validated['resolution'] ?? null),
                'resolved_at' => $isResolved ? ($lockedReport->resolved_at ?? now()) : null,
            ])->save();

            $this->auditRecorder->record(
                event: 'admin.report.updated',
                actor: $actor,
                auditable: $lockedReport,
                metadata: [
                    'status_from' => $previousStatus instanceof ReportStatus ? $previousStatus->value : (string) $previousStatus,
                    'status_to' => $status->value,
                    'assignee_changed' => (int) $previousAssigneeUserId !== (int) $lockedReport->assigned_to_user_id,
                    'assigned_to_user_id' => $lockedReport->assigned_to_user_id,
                    'resolved' => $lockedReport->resolved_at !== null,
                ],
                request: $request,
            );

            return $lockedReport->refresh();
        });
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
