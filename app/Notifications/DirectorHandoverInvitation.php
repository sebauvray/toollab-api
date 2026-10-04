<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class DirectorHandoverInvitation extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private string $schoolName,
        private string $fromName,
        public readonly string $token,
        private Carbon $expiresAt
    ) {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url', 'http://localhost:3000').'/passation-direction?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Invitation à reprendre la direction de '.$this->schoolName)
            ->view('emails.director-handover-invitation', [
                'actionUrl' => $url,
                'schoolName' => $this->schoolName,
                'fromName' => $this->fromName,
                'expiresAt' => $this->expiresAt->copy()->timezone(config('toollab.display_timezone'))->format('d/m/Y \à H\hi'),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'school_name' => $this->schoolName,
            'from_name' => $this->fromName,
        ];
    }
}
