<?php

namespace App\Actions;

use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignOutOtherDatabaseSessions
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        User $owner,
        string $currentPassword,
        ?string $currentSessionId,
    ): int {
        Gate::forUser($owner)->authorize('update', $owner);

        if (! Hash::check($currentPassword, (string) $owner->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The current password is incorrect.')],
            ]);
        }

        if (! $this->isSupported()) {
            $this->auditRecorder->record(
                event: 'account.other_sessions_logout_requested',
                actor: $owner,
                auditable: $owner,
                metadata: ['supported' => false, 'deleted_session_count' => 0],
            );

            return 0;
        }

        if ($currentSessionId === null || $currentSessionId === '') {
            throw ValidationException::withMessages([
                'session' => [__('The current session could not be identified safely.')],
            ]);
        }

        return DB::transaction(function () use ($owner, $currentSessionId): int {
            $owner = User::query()->lockForUpdate()->findOrFail($owner->getKey());
            Gate::forUser($owner)->authorize('update', $owner);

            $deleted = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $owner->getKey())
                ->where('id', '!=', $currentSessionId)
                ->delete();

            $owner->setRememberToken(Str::random(60));
            $owner->save();

            $this->auditRecorder->record(
                event: 'account.other_sessions_logged_out',
                actor: $owner,
                auditable: $owner,
                metadata: [
                    'supported' => true,
                    'deleted_session_count' => $deleted,
                ],
            );

            return $deleted;
        });
    }

    private function isSupported(): bool
    {
        $table = (string) config('session.table', 'sessions');

        return config('session.driver') === 'database'
            && $table !== ''
            && Schema::hasTable($table);
    }
}
