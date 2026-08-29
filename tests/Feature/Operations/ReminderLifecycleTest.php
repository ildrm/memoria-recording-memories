<?php

use App\Enums\ReminderFrequency;
use App\Filament\App\Resources\ReminderResource\Pages\CreateReminder;
use App\Filament\App\Resources\ReminderResource\Pages\EditReminder;
use App\Jobs\DispatchDueReminders;
use App\Models\Reminder;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\ReminderSchedule;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('a reminder created and edited in Filament always receives a usable next run', function (): void {
    CarbonImmutable::setTestNow('2026-03-07 13:00:00 UTC');
    $owner = User::factory()->create();
    $owner->preferences()->update(['timezone' => 'America/New_York']);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $this->actingAs($owner);

    Livewire::test(CreateReminder::class)
        ->fillForm([
            'name' => 'Morning pages',
            'frequency' => ReminderFrequency::Daily->value,
            'local_time' => '09:30',
            'channels' => ['database'],
            'is_enabled' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reminder = Reminder::query()->whereBelongsTo($owner, 'owner')->firstOrFail();
    expect($reminder->next_run_at?->toIso8601String())->toBe('2026-03-07T14:30:00+00:00');

    Livewire::test(EditReminder::class, ['record' => $reminder->getKey()])
        ->fillForm([
            'frequency' => ReminderFrequency::Custom->value,
            'interval_days' => 3,
            'local_time' => '10:15',
            'timezone' => 'America/New_York',
            'channels' => ['mail', 'database'],
            'is_enabled' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($reminder->refresh()->frequency)->toBe(ReminderFrequency::Custom)
        ->and($reminder->interval_days)->toBe(3)
        ->and($reminder->day_of_week)->toBeNull()
        ->and($reminder->day_of_month)->toBeNull()
        ->and($reminder->next_run_at?->toIso8601String())->toBe('2026-03-07T15:15:00+00:00');

    Livewire::test(EditReminder::class, ['record' => $reminder->getKey()])
        ->fillForm(['is_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($reminder->refresh()->next_run_at)->toBeNull();
    expect($reminder->local_time)->toBe('10:15:00');
});

test('daily weekly and monthly schedules preserve local wall time across daylight saving changes', function (): void {
    $now = CarbonImmutable::parse('2026-03-07 14:31:00 UTC');
    $schedule = app(ReminderSchedule::class);

    $daily = Reminder::factory()->make([
        'frequency' => ReminderFrequency::Daily,
        'local_time' => '09:30:00',
        'timezone' => 'America/New_York',
    ]);
    $weekly = Reminder::factory()->make([
        'frequency' => ReminderFrequency::Weekly,
        'day_of_week' => 7,
        'local_time' => '09:30:00',
        'timezone' => 'America/New_York',
    ]);
    $monthly = Reminder::factory()->make([
        'frequency' => ReminderFrequency::Monthly,
        'day_of_month' => 8,
        'local_time' => '09:30:00',
        'timezone' => 'America/New_York',
    ]);

    expect($schedule->following($daily, $now)->toIso8601String())->toBe('2026-03-08T13:30:00+00:00')
        ->and($schedule->following($weekly, $now)->toIso8601String())->toBe('2026-03-08T13:30:00+00:00')
        ->and($schedule->following($monthly, $now)->toIso8601String())->toBe('2026-03-08T13:30:00+00:00');
});

test('recurring reminders use a deterministic daylight saving transition policy', function (): void {
    $schedule = app(ReminderSchedule::class);
    $reminder = Reminder::factory()->make([
        'frequency' => ReminderFrequency::Daily,
        'local_time' => '02:30:00',
        'timezone' => 'America/New_York',
    ]);

    expect($schedule->initial(
        $reminder,
        CarbonImmutable::parse('2026-03-08 06:00:00 UTC'),
    )->toIso8601String())->toBe('2026-03-08T07:30:00+00:00');

    $reminder->local_time = '01:30:00';

    expect($schedule->initial(
        $reminder,
        CarbonImmutable::parse('2026-11-01 04:00:00 UTC'),
    )->toIso8601String())->toBe('2026-11-01T05:30:00+00:00')
        ->and($schedule->following(
            $reminder,
            CarbonImmutable::parse('2026-11-01 05:31:00 UTC'),
        )->toIso8601String())->toBe('2026-11-02T06:30:00+00:00');
});

test('a custom reminder repeats by local calendar days and delivers through the database channel', function (): void {
    CarbonImmutable::setTestNow('2026-03-07 14:31:00 UTC');
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'notification_preferences' => ['writing_reminders' => true],
    ]);
    $reminder = Reminder::factory()->for($owner, 'owner')->create([
        'frequency' => ReminderFrequency::Custom,
        'interval_days' => 2,
        'local_time' => '09:30:00',
        'timezone' => 'America/New_York',
        'channels' => ['database'],
        'next_run_at' => now()->subMinute(),
    ]);

    app(DispatchDueReminders::class)->handle(app(AuditRecorder::class));

    expect($reminder->refresh()->last_sent_at)->not->toBeNull()
        ->and($reminder->next_run_at?->toIso8601String())->toBe('2026-03-09T13:30:00+00:00')
        ->and($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->firstOrFail()->data)->toMatchArray([
            'reminder_name' => 'A quiet moment to write',
        ]);
});
