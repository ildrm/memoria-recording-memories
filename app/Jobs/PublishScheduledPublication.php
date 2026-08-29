<?php

namespace App\Jobs;

use App\Actions\PublishPublication;
use App\Enums\PublicationStatus;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PublishScheduledPublication implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $publicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->publicationId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('publication:'.$this->publicationId))->expireAfter(120)];
    }

    public function handle(PublishPublication $publishPublication): void
    {
        $publication = Publication::query()->find($this->publicationId);
        if ($publication === null
            || $publication->status !== PublicationStatus::Scheduled
            || $publication->scheduled_at === null
            || CarbonImmutable::parse($publication->scheduled_at)->isFuture()
        ) {
            return;
        }

        $publishPublication->scheduled($publication);
    }
}
