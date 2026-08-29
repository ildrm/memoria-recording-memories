<?php

use App\Actions\ListDatabaseSessions;
use App\Actions\SignOutOtherDatabaseSessions;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('database session tools expose metadata and revoke only other sessions after password confirmation', function (): void {
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');
    $owner = User::factory()->create(['password' => 'fictional-current-password']);
    $rememberToken = $owner->getRememberToken();
    DB::table('sessions')->insert([
        [
            'id' => 'current-session',
            'user_id' => $owner->getKey(),
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Current Browser',
            'payload' => 'opaque-session-payload',
            'last_activity' => 200,
        ],
        [
            'id' => 'other-session',
            'user_id' => $owner->getKey(),
            'ip_address' => '198.51.100.20',
            'user_agent' => 'Other Browser',
            'payload' => 'other-opaque-payload',
            'last_activity' => 100,
        ],
    ]);

    $listed = app(ListDatabaseSessions::class)->handle($owner, 'current-session');

    expect($listed['supported'])->toBeTrue()
        ->and($listed['sessions'])->toHaveCount(2)
        ->and($listed['sessions'][0])->toMatchArray([
            'id' => 'current-session',
            'is_current' => true,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Current Browser',
            'last_activity' => 200,
        ])
        ->and($listed['sessions'][0])->not->toHaveKey('payload');

    expect(fn () => app(SignOutOtherDatabaseSessions::class)->handle(
        $owner,
        'wrong-password',
        'current-session',
    ))->toThrow(ValidationException::class);
    expect(DB::table('sessions')->where('user_id', $owner->getKey())->count())->toBe(2);

    $deleted = app(SignOutOtherDatabaseSessions::class)->handle(
        $owner,
        'fictional-current-password',
        'current-session',
    );

    expect($deleted)->toBe(1)
        ->and(DB::table('sessions')->where('id', 'current-session')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse()
        ->and($owner->refresh()->getRememberToken())->not->toBe($rememberToken)
        ->and(AuditEvent::query()
            ->where('event', 'account.other_sessions_logged_out')
            ->where('actor_user_id', $owner->getKey())
            ->exists())->toBeTrue();
});

test('session tools fail safely when the configured session driver is not database', function (): void {
    config()->set('session.driver', 'array');
    $owner = User::factory()->create(['password' => 'fictional-current-password']);

    expect(app(ListDatabaseSessions::class)->handle($owner))->toBe([
        'supported' => false,
        'sessions' => [],
    ])->and(app(SignOutOtherDatabaseSessions::class)->handle(
        $owner,
        'fictional-current-password',
        null,
    ))->toBe(0);
});
