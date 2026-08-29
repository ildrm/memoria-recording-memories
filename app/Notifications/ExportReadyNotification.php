<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $exportId,
        public readonly string $expiresAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your diary export is ready'))
            ->line(__('Your requested diary archive is ready to download securely.'))
            ->line(__('The download expires at :time.', ['time' => $this->expiresAt]))
            ->action(__('Download export'), route('exports.download', $this->exportId));
    }
}
