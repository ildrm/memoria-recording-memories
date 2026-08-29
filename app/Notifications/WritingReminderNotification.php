<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WritingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        public readonly string $reminderName,
        public readonly array $channels = ['mail'],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_intersect($this->channels, ['mail', 'database']));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('A gentle writing reminder'))
            ->greeting($this->reminderName)
            ->line(__('Take a quiet moment to record what matters to you.'))
            ->action(__('Write a memory'), route('filament.app.pages.dashboard'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('A gentle writing reminder'),
            'reminder_name' => $this->reminderName,
            'message' => __('Take a quiet moment to record what matters to you.'),
            'action_url' => route('filament.app.pages.dashboard'),
        ];
    }
}
