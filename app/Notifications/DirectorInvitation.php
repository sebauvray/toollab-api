<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class DirectorInvitation extends Notification
{
    private $schoolName;
    private $token;
    private $frontendUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $schoolName, string $token)
    {
        $this->schoolName = $schoolName;
        $this->token = $token;
        $this->frontendUrl = config('app.frontend_url', 'http://localhost:3000');
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
            ->subject('Invitation en tant que directeur d\'école')
            ->view('emails.director-invitation', [
                'actionUrl' => $url,
                'schoolName' => $this->schoolName,
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
            'invitation_token' => $this->token,
        ];
    }
}
