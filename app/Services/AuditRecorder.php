<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?User $actor = null,
        ?Model $auditable = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditEvent {
        $attributes = [
            'actor_user_id' => $actor?->getKey(),
            'event' => Str::limit($event, 120, ''),
            'ip_address_hash' => $this->fingerprint($request?->ip()),
            'user_agent_hash' => $this->fingerprint($request?->userAgent()),
            'metadata' => $this->sanitizeMetadata($metadata),
            'occurred_at' => now(),
        ];

        if ($auditable !== null) {
            $attributes['auditable_type'] = $auditable->getMorphClass();
            $attributes['auditable_id'] = $auditable->getKey();
        }

        $auditEvent = new AuditEvent;
        $auditEvent->forceFill($attributes);
        $auditEvent->save();

        return $auditEvent;
    }

    private function fingerprint(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, bool|float|int|string|null>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sensitiveTerms = [
            'body',
            'content',
            'password',
            'secret',
            'token',
            'recovery',
            'authorization',
        ];

        return collect(Arr::dot($metadata))
            ->reject(function (mixed $value, string $key) use ($sensitiveTerms): bool {
                return Str::contains(Str::lower($key), $sensitiveTerms)
                    || (! is_scalar($value) && $value !== null);
            })
            ->map(function (mixed $value): bool|float|int|string|null {
                if (is_string($value)) {
                    return Str::limit($value, 500, '');
                }

                return $value;
            })
            ->all();
    }
}
