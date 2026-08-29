<?php

namespace App\Actions;

use App\Models\Entry;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\ShareLinks\CreatedShareLink;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateShareLink
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        Entry $entry,
        User $owner,
        ?string $label = null,
        ?string $password = null,
        ?DateTimeInterface $expiresAt = null,
        ?int $maxViews = null,
        bool $trackViews = false,
        bool $includeAttachments = false,
    ): CreatedShareLink {
        Gate::forUser($owner)->authorize('share', $entry);

        $validated = Validator::make([
            'label' => $label,
            'password' => $password,
            'max_views' => $maxViews,
            'track_views' => $trackViews,
            'include_attachments' => $includeAttachments,
        ], [
            'label' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:10', 'max:255'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'track_views' => ['boolean'],
            'include_attachments' => ['boolean'],
        ])->validate();

        $expiresAt = $this->validatedExpiration($expiresAt);

        $token = $this->token();
        $shareLink = new ShareLink;
        $shareLink->forceFill([
            'user_id' => $owner->getKey(),
            'entry_id' => $entry->getKey(),
            'publication_id' => null,
            'label' => $validated['label'],
            'token_hash' => hash('sha256', $token),
            'password_hash' => filled($validated['password']) ? Hash::make($validated['password']) : null,
            'include_attachments' => $validated['include_attachments'],
            'track_views' => $validated['track_views'],
            'max_views' => $validated['max_views'],
            'expires_at' => $expiresAt,
        ]);
        $shareLink->save();

        $this->auditRecorder->record(
            event: 'share_link.created',
            actor: $owner,
            auditable: $shareLink,
            metadata: [
                'entry_id' => $entry->getKey(),
                'has_expiration' => $expiresAt !== null,
                'password_protected' => filled($validated['password']),
                'has_view_limit' => $validated['max_views'] !== null,
            ],
        );

        return new CreatedShareLink(
            shareLink: $shareLink,
            token: $token,
            url: route('shares.show', ['token' => $token]),
        );
    }

    private function token(): string
    {
        $bytes = max(32, (int) config('memoria.shares.token_bytes', 32));

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function validatedExpiration(?DateTimeInterface $expiresAt): ?CarbonImmutable
    {
        if ($expiresAt === null) {
            return null;
        }

        $expiration = CarbonImmutable::instance($expiresAt);
        $now = CarbonImmutable::now();
        $maximum = $now->addDays(max(1, (int) config('memoria.shares.maximum_expiration_days', 365)));

        if ($expiration->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages([
                'expires_at' => [__('The private link expiration must be in the future.')],
            ]);
        }

        if ($expiration->greaterThan($maximum)) {
            throw ValidationException::withMessages([
                'expires_at' => [__('The private link expiration cannot be more than :days days away.', [
                    'days' => (int) config('memoria.shares.maximum_expiration_days', 365),
                ])],
            ]);
        }

        return $expiration;
    }
}
