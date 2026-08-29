<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('a successful login updates the account last login timestamp', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 08:15:00 UTC');
    $user = User::factory()->create(['last_login_at' => null]);

    expect(Auth::attempt(['email' => $user->email, 'password' => 'password']))->toBeTrue();

    expect($user->refresh()->last_login_at?->toIso8601String())
        ->toBe('2026-08-29T08:15:00+00:00');
});

test('changing a password updates password changed at without changing it for unrelated saves', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 09:20:00 UTC');
    $user = User::factory()->create(['password_changed_at' => null]);

    $user->update(['name' => 'A renamed account']);
    expect($user->refresh()->password_changed_at)->toBeNull();

    $user->update(['password' => Hash::make('a-new-fictional-password')]);

    expect($user->refresh()->password_changed_at?->toIso8601String())
        ->toBe('2026-08-29T09:20:00+00:00');
});
