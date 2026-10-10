<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Données de démonstration pour l'administration plateforme : trois écoles aux
 * profils de santé différents et 30 jours d'historique de connexions.
 * Local uniquement : php artisan db:seed --class=AdminDemoSeeder
 */
class AdminDemoSeeder extends Seeder
{
    private string $password;

    private array $roles;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->password = Hash::make('password');
        $this->roles = DB::table('roles')->pluck('id', 'slug')->all();

        // Saine : équipe complète, activité quotidienne
        $this->school('Institut An-Nour', 'Lyon', createdDaysAgo: 120, lastActivityDaysAgo: 0,
            director: true, teachers: 4, classes: 6, students: 90);

        // Inactive : plus aucune connexion depuis 25 jours
        $this->school('École Al-Furqan', 'Marseille', createdDaysAgo: 200, lastActivityDaysAgo: 25,
            director: true, teachers: 2, classes: 3, students: 35);

        // En onboarding : récente, sans directeur ni classe, une invitation en attente
        $this->school('Centre Ibn Khaldoun', 'Lille', createdDaysAgo: 5, lastActivityDaysAgo: 1,
            director: false, teachers: 0, classes: 0, students: 0, pendingInvite: true);

        $this->loginHistory();
    }

    private function school(string $name, string $city, int $createdDaysAgo, int $lastActivityDaysAgo,
        bool $director, int $teachers, int $classes, int $students, bool $pendingInvite = false): void
    {
        if (DB::table('schools')->where('name', $name)->exists()) {
            return;
        }

        $created = now()->subDays($createdDaysAgo);
        $slug = Str::slug($name);
        $schoolId = DB::table('schools')->insertGetId([
            'name' => $name, 'city' => $city, 'country' => 'France', 'address' => '1 rue de la Paix',
            'email' => "contact@{$slug}.test", 'access' => true, 'created_at' => $created, 'updated_at' => $created,
        ]);
        $yearId = DB::table('school_years')->insertGetId([
            'school_id' => $schoolId, 'label' => '2026-2027', 'is_active' => true, 'outcomes_open' => false,
            'opened_at' => now()->startOfYear(), 'created_at' => $created, 'updated_at' => $created,
        ]);

        $lastActivity = now()->subDays($lastActivityDaysAgo);

        // Le premier membre (directeur ou administrateur) porte l'activité la plus récente
        $this->staff($schoolId, $director ? 'director' : 'admin', "direction@{$slug}.test", $created, $lastActivity);
        $teacherIds = [];
        for ($i = 1; $i <= $teachers; $i++) {
            $teacherIds[] = $this->staff($schoolId, 'teacher', "prof{$i}@{$slug}.test", $created,
                $lastActivity->copy()->subDays(rand(0, 6)));
        }
        if ($pendingInvite) {
            $this->staff($schoolId, 'teacher', "invite@{$slug}.test", now()->subDays(2), null, accepted: false);
        }

        $classIds = [];
        for ($i = 1; $i <= $classes; $i++) {
            $classIds[] = DB::table('classrooms')->insertGetId([
                'school_id' => $schoolId, 'school_year_id' => $yearId, 'name' => "Niveau {$i}",
                'years' => now()->year, 'type' => 'Arabe', 'size' => 20, 'gender' => 'Enfants',
                'main_teacher_id' => $teacherIds ? $teacherIds[($i - 1) % count($teacherIds)] : null,
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        for ($i = 1; $i <= $students; $i++) {
            $familyId = DB::table('families')->insertGetId(['school_id' => $schoolId, 'created_at' => $created, 'updated_at' => $created]);
            $studentId = DB::table('users')->insertGetId([
                'first_name' => fake('fr_FR')->firstName(), 'last_name' => fake('fr_FR')->lastName(),
                'email' => "eleve{$i}.student.".uniqid()."@school.com", 'password' => '',
                'access' => true, 'created_at' => $created, 'updated_at' => $created,
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $studentId, 'role_id' => $this->roles['student'], 'roleable_type' => 'family',
                'roleable_id' => $familyId, 'created_at' => $created, 'updated_at' => $created,
            ]);
            DB::table('student_classrooms')->insert([
                'student_id' => $studentId, 'classroom_id' => $classIds[($i - 1) % count($classIds)],
                'family_id' => $familyId, 'school_year_id' => $yearId, 'status' => 'active',
                'enrollment_date' => $created, 'created_at' => $created, 'updated_at' => $created,
            ]);
        }
    }

    private function staff(int $schoolId, string $role, string $email, Carbon $since, ?Carbon $lastSeen, bool $accepted = true): int
    {
        $userId = DB::table('users')->insertGetId([
            'first_name' => fake('fr_FR')->firstName(), 'last_name' => fake('fr_FR')->lastName(),
            'email' => $email, 'password' => $this->password, 'access' => true,
            'became_staff_at' => $accepted ? $since : null, 'last_login_at' => $lastSeen,
            'created_at' => $since, 'updated_at' => $since,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'role_id' => $this->roles[$role], 'roleable_type' => 'school',
            'roleable_id' => $schoolId, 'accepted_at' => $accepted ? $since : null,
            'created_at' => $since, 'updated_at' => $since,
        ]);
        if ($lastSeen) {
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => User::class, 'tokenable_id' => $userId, 'name' => 'new_token',
                'token' => hash('sha256', Str::random(40)), 'abilities' => '["*"]',
                'last_used_at' => $lastSeen, 'created_at' => $lastSeen, 'updated_at' => $lastSeen,
            ]);
        }

        return $userId;
    }

    /** 30 jours de connexions plausibles : creux le week-end, légère hausse. */
    private function loginHistory(): void
    {
        foreach (range(29, 1) as $daysAgo) {
            $date = today()->subDays($daysAgo);
            $base = $date->isWeekend() ? 2 : 6 + intdiv(30 - $daysAgo, 5);
            $unique = $base + rand(0, 3);
            DB::table('daily_logins')->updateOrInsert(
                ['date' => $date->toDateString()],
                ['unique_users' => $unique, 'logins' => $unique + rand(0, 4)]
            );
        }
    }
}
