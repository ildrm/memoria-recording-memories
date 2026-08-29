<?php

namespace App\Notifications;

use App\Models\SocialPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SocialPostFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $socialPostId) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $socialPost = SocialPost::query()
            ->select(['id', 'publication_id', 'provider', 'status'])
            ->find($this->socialPostId);

        return (new MailMessage)
            ->subject(__('A social publication needs attention'))
            ->line(__('A post could not be sent to the selected social network.'))
            ->line(__('Your private diary content was not included in this notification.'))
            ->when(
                $socialPost !== null,
                fn (MailMessage $message): MailMessage => $message->action(
                    __('Review publication status'),
                    route('app.publications.preview', $socialPost->publication_id),
                ),
            );
    }
}
