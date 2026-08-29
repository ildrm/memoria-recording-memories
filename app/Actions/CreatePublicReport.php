<?php

namespace App\Actions;

use App\Enums\CommentStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\Report;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicPublicationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePublicReport
{
    /** @var array<int, string> */
    public const REASONS = [
        'spam',
        'harassment',
        'hate',
        'safety',
        'copyright',
        'privacy',
        'other',
    ];

    public function __construct(
        private readonly PublicPublicationGuard $publicationGuard,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(
        User $reporter,
        Publication|Comment $target,
        string $reason,
        ?string $details = null,
        ?Request $request = null,
    ): Report {
        $reason = Str::lower(trim($reason));
        $details = filled($details) ? trim((string) $details) : null;

        if (! in_array($reason, self::REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => [__('Select a valid report reason.')],
            ]);
        }

        if ($details !== null && Str::length($details) > 2000) {
            throw ValidationException::withMessages([
                'details' => [__('Report details may not exceed 2,000 characters.')],
            ]);
        }

        return DB::transaction(function () use (
            $reporter,
            $target,
            $reason,
            $details,
            $request,
        ): Report {
            Gate::forUser($reporter)->authorize('create', Report::class);

            if ($target instanceof Publication) {
                $publication = $this->publicationGuard->resolve($target, forUpdate: true);
                $comment = null;
            } else {
                $comment = Comment::query()
                    ->whereKey($target->getKey())
                    ->where('status', CommentStatus::Approved)
                    ->lockForUpdate()
                    ->firstOrFail();
                $publication = Publication::query()->findOrFail($comment->publication_id);
                $this->publicationGuard->resolve($publication, forUpdate: true);
            }

            $existing = Report::query()
                ->whereBelongsTo($reporter, 'reporter')
                ->whereIn('status', [ReportStatus::Open, ReportStatus::InReview])
                ->when(
                    $comment === null,
                    fn ($query) => $query
                        ->where('publication_id', $publication->getKey())
                        ->whereNull('comment_id'),
                    fn ($query) => $query
                        ->where('comment_id', $comment?->getKey())
                        ->whereNull('publication_id'),
                )
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $report = new Report;
            $report->forceFill([
                'publication_id' => $comment === null ? $publication->getKey() : null,
                'comment_id' => $comment?->getKey(),
                'reporter_user_id' => $reporter->getKey(),
                'reason' => $reason,
                'details' => $details,
                'status' => ReportStatus::Open,
            ]);
            $report->save();

            $this->auditRecorder->record(
                event: 'public_report.created',
                actor: $reporter,
                auditable: $report,
                metadata: [
                    'target_type' => $comment === null ? 'publication' : 'comment',
                    'target_id' => $comment?->getKey() ?? $publication->getKey(),
                    'reason' => $reason,
                    'has_details' => $details !== null,
                ],
                request: $request,
            );

            return $report;
        });
    }
}
