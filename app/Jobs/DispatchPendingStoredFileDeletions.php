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
            ->whereNull('failed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('last_attempted_at')
                    ->orWhere('last_attempted_at', '<=', now()->subMinutes(5));
            })
            ->orderBy('id')
            ->limit((int) config('memoria.scheduler.batch_size', 100))
            ->pluck('id')
            ->each(fn (int $deletionId) => DeleteStoredFile::dispatch($deletionId));
    }
}
