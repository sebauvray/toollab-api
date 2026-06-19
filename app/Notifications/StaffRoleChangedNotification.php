<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffRoleChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private string $schoolName,
        private string $action,
        private array $changedRoles,
        private array $currentRoles
    ) {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.staff-role-changed', [
                'schoolName' => $this->schoolName,
                'action' => $this->action,
                'changedRoles' => $this->changedRoles,
                'currentRoles' => $this->currentRoles,
                'notifiable' => $notifiable,
            ]);
    }

    private function subject(): string
    {
        return match ($this->action) {
            'added' => 'Nouveau rôle dans ' . $this->schoolName,
            'removed' => 'Rôle retiré dans ' . $this->schoolName,
            'removed_from_school' => 'Accès retiré à ' . $this->schoolName,
            default => 'Mise à jour de vos rôles',
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'school_name' => $this->schoolName,
            'action' => $this->action,
            'changed_roles' => $this->changedRoles,
            'current_roles' => $this->currentRoles,
        ];
    }
}
