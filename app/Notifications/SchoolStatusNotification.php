<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient le directeur de la suspension ou de la réactivation de son établissement. */
class SchoolStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private string $schoolName,
        private string $status,
        private ?string $reason,
        private ?string $replyTo
    ) {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->status === 'suspended' ? 'Établissement suspendu : ' : 'Établissement réactivé : ').$this->schoolName)
            ->view('emails.school-status', [
                'status' => $this->status,
                'schoolName' => $this->schoolName,
                'reason' => $this->reason,
                'name' => trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? '')) ?: $notifiable->email,
            ]);

        return $this->replyTo ? $mail->replyTo($this->replyTo) : $mail;
    }
}
