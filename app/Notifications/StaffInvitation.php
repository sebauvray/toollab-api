<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    private $schoolName;
    private $roleName;
    private $token;
    private $frontendUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $schoolName, string $roleName, string $token)
    {
        $this->schoolName = $schoolName;
        $this->roleName = $roleName;
        $this->token = $token;
        $this->frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $this->afterCommit = true;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->frontendUrl . '/set-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Invitation à rejoindre ' . $this->schoolName)
            ->view('emails.staff-invitation', [
                'actionUrl' => $url,
                'schoolName' => $this->schoolName,
                'roleName' => $this->roleName,
                'notifiable' => $notifiable
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'school_name' => $this->schoolName,
            'role_name' => $this->roleName,
            'invitation_token' => $this->token,
        ];
    }
}
