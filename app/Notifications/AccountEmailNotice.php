<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class AccountEmailNotice extends Notification {
    public function __construct(public bool $completed) {}
    public function via($notifiable): array { return ['mail']; }
    public function toMail($notifiable): MailMessage {
        return (new MailMessage)->subject($this->completed ? 'Votre adresse Toollab a changé' : 'Demande de changement d’adresse Toollab')
            ->greeting('Bonjour,')
            ->line($this->completed ? 'L’adresse de connexion de votre compte a été modifiée. Vos sessions ont été fermées.' : 'Un changement d’adresse de connexion a été demandé. Votre adresse actuelle reste valable jusqu’à confirmation de la nouvelle.')
            ->line('Si vous ne reconnaissez pas cette opération, contactez immédiatement le support Toollab.')
            ->action('Contacter le support', rtrim(config('app.frontend_url', 'http://localhost:3000'), '/').'/account-help');
    }
}
