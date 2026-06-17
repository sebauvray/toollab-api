<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;

class CreateSuperAdmin extends Command
{
    protected $signature = 'toollab:create-super-admin
                            {email? : Email du super-admin (défaut : TOUS ceux de SUPER_ADMIN_EMAILS)}
                            {--password= : Mot de passe (sinon demandé en interactif)}
                            {--first-name= : Prénom (à la création uniquement)}
                            {--last-name= : Nom (à la création uniquement)}';

    protected $description = 'Crée les super-admins plateforme (ou réinitialise leur mot de passe). is_super_admin est dérivé de SUPER_ADMIN_EMAILS, aucune école n\'est rattachée.';

    public function handle(): int
    {
        $superEmails = config('toollab.super_admin_emails', []);

        $argEmail = $this->argument('email');
        $emails = $argEmail ? [$argEmail] : $superEmails;

        if (empty($emails)) {
            $this->error('Aucun email fourni et SUPER_ADMIN_EMAILS est vide. Renseignez SUPER_ADMIN_EMAILS dans .env ou passez un email en argument.');
            return self::FAILURE;
        }

        if ($argEmail && !in_array($argEmail, $superEmails, true)) {
            $list = $superEmails ? implode(', ', $superEmails) : 'vide';
            $this->warn("{$argEmail} n'est pas dans SUPER_ADMIN_EMAILS ({$list}). L'utilisateur sera créé mais ne deviendra super-admin qu'une fois l'email ajouté à cette variable.");
            if (!$this->confirm('Continuer quand même ?', false)) {
                return self::FAILURE;
            }
        }

        $sharedPassword = $this->option('password');
        if ($sharedPassword !== null && strlen($sharedPassword) < 8) {
            $this->error('Le mot de passe doit faire au moins 8 caractères.');
            return self::FAILURE;
        }
        if ($sharedPassword !== null && count($emails) > 1) {
            $this->warn('Le même mot de passe est appliqué à '.count($emails).' comptes — chacun pourra le changer via « mot de passe oublié ».');
        }

        foreach ($emails as $email) {
            $password = $sharedPassword ?? $this->secret("Mot de passe pour {$email} (min. 8 caractères)");
            if (strlen((string) $password) < 8) {
                $this->error("Mot de passe trop court pour {$email} (min. 8 caractères) — ignoré.");
                continue;
            }
            $this->upsertSuperAdmin($email, $password);
        }

        return self::SUCCESS;
    }

    private function upsertSuperAdmin(string $email, string $password): void
    {
        $existed = User::where('email', $email)->exists();

        $user = User::firstOrNew(['email' => $email]);
        $user->first_name = $this->option('first-name') ?: ($user->first_name ?: 'Super');
        $user->last_name = $this->option('last-name') ?: ($user->last_name ?: 'Admin');
        $user->password = $password;
        $user->access = true;
        $user->save();

        $hasSchoolRole = UserRole::where('user_id', $user->id)->where('roleable_type', 'school')->exists();

        $this->info(($existed ? 'Mot de passe réinitialisé' : 'Super-admin créé').' : '.$email);
        $this->line('  is_super_admin : '.($user->is_super_admin ? 'oui' : 'NON — ajoutez cet email à SUPER_ADMIN_EMAILS'));
        $this->line($hasSchoolRole
            ? '  Rôle école présent : la connexion ouvre son école (bascule vers /admin via le menu compte).'
            : '  Aucune école rattachée : la connexion mène directement à l\'interface /admin.');
    }
}
