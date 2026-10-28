<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Compte de test local : parent d'une famille de l'école, invité comme admin
 * mais n'ayant pas encore accepté (accepted_at = null). Sert à vérifier qu'une
 * invitation en attente ne donne aucun droit staff.
 *
 * Crée aussi deux comptes staff acceptés pour tester les vues par rôle :
 * registar.test@toollab.test et prof.test@toollab.test.
 *
 * php artisan db:seed --class=PendingInviteSeeder
 * Mot de passe de ces comptes : password
 */
class PendingInviteSeeder extends Seeder
{
    public function run(int $schoolId = 1): void
    {
        $user = User::updateOrCreate(
            ['email' => 'parent.invite@toollab.test'],
            [
                'first_name' => 'Parent',
                'last_name' => 'Invité',
                'password' => Hash::make('password'),
                'access' => true,
            ]
        );

        $family = Family::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->firstOrFail();

        UserRole::withoutGlobalScopes()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => Role::where('slug', 'responsible')->value('id'),
                'roleable_type' => 'family',
                'roleable_id' => $family->id,
            ],
            ['accepted_at' => now(), 'deleted_at' => null]
        );

        UserRole::withoutGlobalScopes()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => Role::staffFor($schoolId, 'admin')->id,
                'roleable_type' => 'school',
                'roleable_id' => $schoolId,
            ],
            ['accepted_at' => null, 'deleted_at' => null]
        );

        foreach (['registar' => 'registar.test@toollab.test', 'teacher' => 'prof.test@toollab.test'] as $slug => $email) {
            $staff = User::updateOrCreate(
                ['email' => $email],
                ['first_name' => ucfirst($slug), 'last_name' => 'Test', 'password' => Hash::make('password'), 'access' => true]
            );
            UserRole::withoutGlobalScopes()->updateOrCreate(
                ['user_id' => $staff->id, 'role_id' => Role::staffFor($schoolId, $slug)->id, 'roleable_type' => 'school', 'roleable_id' => $schoolId],
                ['accepted_at' => now(), 'deleted_at' => null]
            );
        }

        $this->command?->info("Parent invité rattaché à la famille #{$family->id}, invitation admin en attente sur l'école #{$schoolId}.");
    }
}
