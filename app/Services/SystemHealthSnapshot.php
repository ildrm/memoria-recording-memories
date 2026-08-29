<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SystemHealthSnapshot
{
    /**
     * @return array{
     *     checks: array<string, array{label: string, state: string, status: 'success'|'warning'|'danger', description: string}>,
     *     counts: array{pending_jobs: int|null, failed_jobs: int|null},
     *     checked_at: string
     * }
     */
    public function for(User $actor): array
    {
        if (! $actor->hasPermissionTo('system.manage')) {
            throw new AuthorizationException;
        }

        $database = $this->databaseCheck();
        $cache = $this->cacheCheck();
        $queue = $this->queueCheck();
        $failedJobs = $this->failedJobsCheck();

        return [
            'checks' => [
                'application' => [
                    'label' => __('Application'),
                    'state' => __('Available'),
                    'status' => 'success',
                    'description' => __('The authenticated administration request completed normally.'),
                ],
                'database' => $database,
                'cache' => $cache,
                'queue' => $queue['check'],
                'failed_jobs' => $failedJobs['check'],
            ],
            'counts' => [
                'pending_jobs' => $queue['pending_jobs'],
                'failed_jobs' => $failedJobs['failed_jobs'],
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{label: string, state: string, status: 'success'|'warning'|'danger', description: string} */
    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return [
                'label' => __('Database'),
                'state' => __('Reachable'),
                'status' => 'success',
                'description' => __('A bounded read-only database probe succeeded.'),
            ];
        } catch (Throwable) {
            return [
                'label' => __('Database'),
                'state' => __('Unavailable'),
                'status' => 'danger',
                'description' => __('The database probe failed. Review protected server logs for details.'),
            ];
        }
    }

    /** @return array{label: string, state: string, status: 'success'|'warning'|'danger', description: string} */
    private function cacheCheck(): array
    {
        $key = 'memoria:health:'.Str::random(32);
        $value = Str::random(32);
        $probeSucceeded = false;
        $cleanupSucceeded = true;

        try {
            $stored = Cache::put($key, $value, 10);
            $matches = is_string($cached = Cache::get($key)) && hash_equals($value, $cached);
            $probeSucceeded = $stored && $matches;
        } catch (Throwable) {
            $probeSucceeded = false;
        }

        try {
            Cache::forget($key);
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }

        if ($probeSucceeded && $cleanupSucceeded) {
            return [
                'label' => __('Cache'),
                'state' => __('Reachable'),
                'status' => 'success',
                'description' => __('A short-lived isolated cache probe succeeded and was removed.'),
            ];
        }

        return [
            'label' => __('Cache'),
            'state' => __('Unavailable'),
            'status' => 'danger',
            'description' => __('The cache probe failed. Review protected server logs for details.'),
        ];
    }

    /**
     * @return array{
     *     check: array{label: string, state: string, status: 'success'|'warning'|'danger', description: string},
     *     pending_jobs: int|null
     * }
     */
    private function queueCheck(): array
    {
        $connectionName = (string) config('queue.default');
        $connection = config("queue.connections.{$connectionName}");
        $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;

        if (! is_string($driver) || $driver === '' || $driver === 'null') {
            return [
                'check' => [
                    'label' => __('Queue'),
                    'state' => __('Not configured'),
                    'status' => 'danger',
                    'description' => __('Background work is not configured for delivery.'),
                ],
                'pending_jobs' => null,
            ];
        }

        if (in_array($driver, ['sync', 'deferred', 'background'], true)) {
            return [
                'check' => [
                    'label' => __('Queue'),
                    'state' => __('Runs with requests'),
                    'status' => 'warning',
                    'description' => __('Work is configured without a durable worker queue. Confirm this is intentional for the environment.'),
                ],
                'pending_jobs' => null,
            ];
        }

        $pendingJobs = $driver === 'database' && is_array($connection)
            ? $this->databaseQueueCount($connection)
            : null;

        return [
            'check' => [
                'label' => __('Queue'),
                'state' => $pendingJobs === null ? __('Configured') : __('Storage reachable'),
                'status' => $pendingJobs === null ? 'warning' : 'success',
                'description' => $pendingJobs === null
                    ? __('A queue connection is configured, but this bounded check cannot confirm worker liveness.')
                    : __('Queue storage is reachable. This check counts stored work but does not claim that a worker is running.'),
            ],
            'pending_jobs' => $pendingJobs,
        ];
    }

    /** @param array<string, mixed> $connection */
    private function databaseQueueCount(array $connection): ?int
    {
        $table = $connection['table'] ?? null;
        $databaseConnection = $connection['connection'] ?? null;

        if (! $this->isSafeTableName($table)) {
            return null;
        }

        try {
            $schema = is_string($databaseConnection) && $databaseConnection !== ''
                ? Schema::connection($databaseConnection)
                : Schema::connection(DB::getDefaultConnection());

            if (! $schema->hasTable($table)) {
                return null;
            }

            return (int) DB::connection(is_string($databaseConnection) ? $databaseConnection : null)
                ->table($table)
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     check: array{label: string, state: string, status: 'success'|'warning'|'danger', description: string},
     *     failed_jobs: int|null
     * }
     */
    private function failedJobsCheck(): array
    {
        $table = config('queue.failed.table');
        $databaseConnection = config('queue.failed.database');

        if (! $this->isSafeTableName($table)) {
            return $this->unavailableFailedJobsCheck();
        }

        try {
            $schema = is_string($databaseConnection) && $databaseConnection !== ''
                ? Schema::connection($databaseConnection)
                : Schema::connection(DB::getDefaultConnection());

            if (! $schema->hasTable($table)) {
                return $this->unavailableFailedJobsCheck();
            }

            $failedJobs = (int) DB::connection(is_string($databaseConnection) ? $databaseConnection : null)
                ->table($table)
                ->count();

            return [
                'check' => [
                    'label' => __('Failed jobs'),
                    'state' => $failedJobs === 0 ? __('None recorded') : __('Attention needed'),
                    'status' => $failedJobs === 0 ? 'success' : 'danger',
                    'description' => $failedJobs === 0
                        ? __('No failed job records are currently stored.')
                        : trans_choice(':count failed job record is stored.|:count failed job records are stored.', $failedJobs, ['count' => $failedJobs]),
                ],
                'failed_jobs' => $failedJobs,
            ];
        } catch (Throwable) {
            return $this->unavailableFailedJobsCheck();
        }
    }

    /**
     * @return array{
     *     check: array{label: string, state: string, status: 'warning', description: string},
     *     failed_jobs: null
     * }
     */
    private function unavailableFailedJobsCheck(): array
    {
        return [
            'check' => [
                'label' => __('Failed jobs'),
                'state' => __('Count unavailable'),
                'status' => 'warning',
                'description' => __('Failed-job storage is unavailable to this bounded check.'),
            ],
            'failed_jobs' => null,
        ];
    }

    private function isSafeTableName(mixed $table): bool
    {
        return is_string($table)
            && preg_match('/\A[A-Za-z0-9_]+\z/', $table) === 1;
    }
}
