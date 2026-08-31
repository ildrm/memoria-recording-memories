<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchPendingStoredFileDeletions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        DB::table('stored_file_deletions')
            ->whereNull('completed_at')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pendingQuery): void {
                    $pendingQuery->whereNull('failed_at')
                        ->where(function (Builder $attemptQuery): void {
                            $attemptQuery->whereNull('last_attempted_at')
                                ->orWhere('last_attempted_at', '<=', now()->subMinutes(5));
                        });
                })->orWhere(
                    'failed_at',
                    '<=',
                    now()->subHours((int) config('memoria.file_cleanup.retry_failed_after_hours', 24)),
                );
            })
            ->orderBy('id')
            ->limit((int) config('memoria.scheduler.batch_size', 100))
            ->pluck('id')
            ->each(fn (int $deletionId) => DeleteStoredFile::dispatch($deletionId));
    }
}
