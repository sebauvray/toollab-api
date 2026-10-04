<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DirectorHandoverStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private string $schoolName,
        public readonly string $action,
        private string $counterpartName,
        private array $remainingRoleNames
    ) {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->action === 'accepted'
            ? 'Passation de direction effectuée — '.$this->schoolName
            : 'Passation de direction refusée — '.$this->schoolName;

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.director-handover-status', [
                'schoolName' => $this->schoolName,
                'action' => $this->action,
                'counterpartName' => $this->counterpartName,
                'remainingRoleNames' => $this->remainingRoleNames,
                'notifiable' => $notifiable,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'school_name' => $this->schoolName,
            'action' => $this->action,
            'remaining_roles' => $this->remainingRoleNames,
        ];
    }
}
