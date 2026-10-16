<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class AccountEmailConfirmation extends Notification {
    public function __construct(public string $token, public bool $recovery) {}
    public function via($notifiable): array { return ['mail']; }
    public function toMail($notifiable): MailMessage {
        // Fragment : le jeton n'est pas envoyé dans les journaux HTTP ou le Referer.
        $url = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/').'/confirm-email#token='.$this->token;
        return (new MailMessage)->subject('Confirmer votre nouvelle adresse Toollab')
            ->greeting('Bonjour,')
            ->line($this->recovery ? 'Le support a lancé la récupération de votre compte après vérification de votre identité.' : 'Vous avez demandé à changer votre adresse de connexion.')
            ->line('Ce changement concerne votre compte dans toutes vos écoles. Le lien expire dans 60 minutes.')
            ->action('Confirmer mon adresse', $url)
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.');
    }
}
