<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Services\StoredFileCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ExpireUserExports implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public function handle(?StoredFileCleanup $storedFileCleanup = null): void
    {
        $storedFileCleanup ??= app(StoredFileCleanup::class);

        Export::query()
            ->where('status', ExportStatus::Ready)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($exports) use ($storedFileCleanup): void {
                foreach ($exports as $export) {
                    DB::transaction(function () use ($export, $storedFileCleanup): void {
                        $export = Export::query()->lockForUpdate()->find($export->getKey());
                        if ($export === null
                            || $export->status !== ExportStatus::Ready
                            || $export->expires_at === null
                            || $export->expires_at->isFuture()) {
                            return;
                        }

                        if ($export->disk !== null && $export->path !== null) {
                            $storedFileCleanup->schedule(
                                $export->disk,
                                $export->path,
                                'user_export_expired',
                            );
                        }

                        $export->forceFill([
                            'status' => ExportStatus::Expired,
                            'path' => null,
                            'disk' => null,
                        ])->save();
                    });
                }
            });
    }
}
