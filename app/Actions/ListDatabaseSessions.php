<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class ListDatabaseSessions
{
    /**
     * @return array{supported: bool, sessions: array<int, array{id: string, is_current: bool, ip_address: string|null, user_agent: string|null, last_activity: int}>}
     */
    public function handle(User $owner, ?string $currentSessionId = null): array
    {
        Gate::forUser($owner)->authorize('update', $owner);

        if (! $this->isSupported()) {
            return ['supported' => false, 'sessions' => []];
        }

        $table = (string) config('session.table', 'sessions');
        $sessions = DB::table($table)
            ->where('user_id', $owner->getKey())
            ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->orderByDesc('last_activity')
            ->limit(100)
            ->get()
            ->map(fn (object $session): array => [
                'id' => (string) $session->id,
                'is_current' => $currentSessionId !== null
                    && hash_equals($currentSessionId, (string) $session->id),
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'user_agent' => is_string($session->user_agent) ? $session->user_agent : null,
                'last_activity' => (int) $session->last_activity,
            ])
            ->all();

        return ['supported' => true, 'sessions' => $sessions];
    }

    private function isSupported(): bool
    {
        $table = (string) config('session.table', 'sessions');

        return config('session.driver') === 'database'
            && $table !== ''
            && Schema::hasTable($table);
    }
}
