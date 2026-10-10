<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Message libre du super-admin au directeur ; les réponses vont directement au super-admin. */
class DirectorMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private string $schoolName,
        private string $subjectLine,
        private string $body,
        private string $senderName,
        private string $replyTo
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine)
            ->replyTo($this->replyTo, $this->senderName)
            ->view('emails.director-message', [
                'subjectLine' => $this->subjectLine,
                'body' => $this->body,
                'senderName' => $this->senderName,
                'schoolName' => $this->schoolName,
                'name' => trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? '')) ?: $notifiable->email,
            ]);
    }
}
