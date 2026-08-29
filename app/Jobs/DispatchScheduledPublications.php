<?php

namespace App\Jobs;

use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchScheduledPublications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        Publication::query()
            ->scheduled()
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->limit((int) config('memoria.scheduler.batch_size', 100))
            ->pluck('id')
            ->each(fn (int $publicationId) => PublishScheduledPublication::dispatch($publicationId));
    }
}
