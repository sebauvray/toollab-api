<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AlQalamSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(RoleSeeder::class);

            $school = School::firstOrCreate(
                ['siret' => '793 766 544 00027'],
                [
                    'name' => 'Association Al Qalam de Vitrolles',
                    'email' => 'alqalam.cccm@gmail.com',
                    'phone' => null,
                    'address' => '1 Boulevard Paul Guigou',
                    'zipcode' => '13127',
                    'city' => 'Vitrolles',
                    'country' => 'France',
                    'logo' => null,
                    'access' => true,
                    'vat_mode' => 'association',
                    'vat_number' => null,
                ]
            );

            $members = [
                ['email' => 'habibmal@hotmail.fr', 'first_name' => 'Habib', 'last_name' => 'Mal', 'role' => 'director'],
                ['email' => 'ajjaj.manelle@gmail.com', 'first_name' => 'Manelle', 'last_name' => 'Ajjaj', 'role' => 'admin'],
                ['email' => 'imene.re13@gmail.com', 'first_name' => 'Imane', 'last_name' => 'REZAGUI', 'role' => 'admin'],
            ];

            foreach ($members as $member) {
                $user = $this->firstOrCreateUser(
                    $member['email'],
                    $member['first_name'],
                    $member['last_name']
                );

                $this->attachSchoolRole($user, $school, $member['role']);
            }

            $this->ensureActiveSchoolYear($school);

            $this->command?->info('Al Qalam prêt : école et membres créés/rattachés sans email.');

            foreach ($members as $member) {
                $this->command?->line($member['role'].' : '.$member['email'].' / password');
            }
        });
    }

    private function firstOrCreateUser(string $email, string $firstName, string $lastName): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => Hash::make('password'),
                'access' => true,
            ]
        );
    }

    private function attachSchoolRole(User $user, School $school, string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        UserRole::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'roleable_type' => 'school',
                'roleable_id' => $school->id,
            ],
            [
                'accepted_at' => now(),
            ]
        );
    }

    private function ensureActiveSchoolYear(School $school): void
    {
        $now = Carbon::now();
        $startYear = $now->month >= 9 ? $now->year : $now->year - 1;
        $yearLabel = $startYear.'-'.($startYear + 1);

        $year = SchoolYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('label', $yearLabel)
            ->first();

        if (! $year) {
            $year = new SchoolYear([
                'label' => $yearLabel,
                'opened_at' => $now,
                'is_active' => true,
            ]);
            $year->school_id = $school->id;
            $year->save();
        }

        SchoolYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('id', '!=', $year->id)
            ->update(['is_active' => false]);
    }
}
