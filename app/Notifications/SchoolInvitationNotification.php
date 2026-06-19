<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation envoyée à un utilisateur DÉJÀ existant (déjà actif dans une autre
 * école) lorsqu'une nouvelle école l'invite. Contrairement à StaffInvitation,
 * il n'y a pas de mot de passe à définir : l'utilisateur accepte simplement en
 * se connectant via le lien. Tant qu'il n'a pas accepté, la nouvelle école ne
 * voit pas son nom/prénom.
 */
class SchoolInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    private string $schoolName;
    private array $roleNames;
    private string $frontendUrl;

    public function __construct(string $schoolName, string|array $roleNames)
    {
        $this->schoolName = $schoolName;
        $this->roleNames = is_array($roleNames) ? array_values($roleNames) : [$roleNames];
        $this->frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // L'acceptation se fait dans l'application (bandeau d'invitations) : le lien
        // amène simplement l'utilisateur à se connecter.
        $url = $this->frontendUrl . '/login';

        return (new MailMessage)
            ->subject('Invitation à rejoindre ' . $this->schoolName)
            ->view('emails.school-invitation', [
                'actionUrl' => $url,
                'schoolName' => $this->schoolName,
                'roleName' => $this->roleNames[0] ?? '',
                'roleNames' => $this->roleNames,
                'notifiable' => $notifiable,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'school_name' => $this->schoolName,
            'role_names' => $this->roleNames,
        ];
    }
}
