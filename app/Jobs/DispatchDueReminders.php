<?php

namespace App\Jobs;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\WritingReminderNotification;
use App\Services\AuditRecorder;
use App\Services\NotificationPreference;
use App\Services\ReminderSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchDueReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public function handle(AuditRecorder $auditRecorder): void
    {
        $notificationPreference = app(NotificationPreference::class);
        $reminderSchedule = app(ReminderSchedule::class);

        Reminder::query()->due()->orderBy('id')->limit(
            (int) config('memoria.scheduler.batch_size', 100),
        )->pluck('id')->each(function (int $reminderId) use ($auditRecorder, $notificationPreference, $reminderSchedule): void {
            DB::transaction(function () use ($reminderId, $auditRecorder, $notificationPreference, $reminderSchedule): void {
                $reminder = Reminder::query()->lockForUpdate()->find($reminderId);

                if ($reminder === null
                    || ! $reminder->is_enabled
                    || $reminder->next_run_at === null
                    || CarbonImmutable::parse($reminder->next_run_at)->isFuture()
                ) {
                    return;
                }

                $owner = User::query()
                    ->whereNull('disabled_at')
                    ->find($reminder->user_id);
                if ($owner === null) {
                    $reminder->forceFill(['is_enabled' => false, 'next_run_at' => null])->save();

                    $auditRecorder->record(
                        event: 'reminder.disabled',
                        auditable: $reminder,
                        metadata: ['reason' => 'owner_unavailable'],
                    );

                    return;
                }

                $configuredChannels = $reminder->getAttribute('channels');
                $channels = is_array($configuredChannels) ? $configuredChannels : ['mail'];
                $enabledChannels = $notificationPreference->allows($owner, 'writing_reminders')
                    ? array_values(array_intersect($channels, ['mail', 'database']))
                    : [];

                if ($enabledChannels !== []) {
                    $owner->notify(
                        (new WritingReminderNotification($reminder->name, $enabledChannels))->afterCommit(),
                    );
                }

                $reminder->forceFill([
                    'last_sent_at' => $enabledChannels !== [] ? now() : $reminder->last_sent_at,
                    'next_run_at' => $reminderSchedule->following($reminder),
                ])->save();
                $auditRecorder->record(
                    event: $enabledChannels !== [] ? 'reminder.dispatched' : 'reminder.skipped',
                    actor: $owner,
                    auditable: $reminder,
                    metadata: [
                        'channel_count' => count($enabledChannels),
                        'reason' => $enabledChannels !== [] ? null : 'notification_preference_or_channel',
                    ],
                );
            });
        });
    }
}
