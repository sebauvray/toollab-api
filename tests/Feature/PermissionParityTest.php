<?php

/*
|--------------------------------------------------------------------------
| Parité rôles → permissions
|--------------------------------------------------------------------------
|
| Avant les permissions, l'accès était codé en dur par rôle (checkrole et
| contrôles dans les contrôleurs). Ce test fige, route par route, qui avait
| accès : avec les rôles par défaut, chaque rôle doit garder exactement les
| mêmes accès. « Autorisé » = ni 401 ni 403 (une 404 ou 422 sur un corps vide
| prouve qu'on a passé le contrôle d'accès).
|
*/

use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const PARITY_ROLES = ['director', 'admin', 'registar', 'teacher'];

function parityUser(string $first): User
{
    return User::create([
        'first_name' => $first,
        'last_name' => 'Parite',
        'email' => strtolower($first).'@parity-test.com',
        'password' => 'password',
        'access' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->school = School::factory()->create(['access' => true]);
    request()->attributes->set('current_school_id', $this->school->id);

    $this->year = SchoolYear::create(['label' => '2025-2026', 'is_active' => true, 'opened_at' => now()]);
    request()->attributes->set('current_school_year_id', $this->year->id);

    $cursus = Cursus::create(['name' => 'Coran', 'progression' => 'continu']);
    $this->classroom = Classroom::create([
        'name' => 'Coran A', 'years' => 2025, 'type' => 'Children', 'size' => 10, 'cursus_id' => $cursus->id,
    ]);

    $this->family = Family::create();
    $this->member = parityUser('Membre');
    UserRole::create([
        'user_id' => $this->member->id,
        'role_id' => Role::global()->where('slug', 'responsible')->value('id'),
        'roleable_type' => 'family',
        'roleable_id' => $this->family->id,
    ]);
});

/**
 * [méthode, uri, rôles autorisés avant la migration vers les permissions].
 * {school} {year} {family} {classroom} {member} sont remplacés à l'exécution.
 */
dataset('routes', [
    ['PUT', '/api/schools/{school}', ['director', 'admin']],
    ['POST', '/api/school-years', ['director', 'admin']],
    ['POST', '/api/school-years/{year}/close', ['director', 'admin']],
    ['POST', '/api/school-years/{year}/outcomes-toggle', ['director', 'admin']],
    ['GET', '/api/school-years/{year}/classrooms', ['director', 'admin']],
    ['POST', '/api/classrooms/{classroom}/reconduct', ['director', 'admin']],
    ['GET', '/api/users/teachers', ['director', 'admin']],
    ['GET', '/api/users/school/{school}', ['director', 'admin']],
    ['GET', '/api/users/classroom/{classroom}', ['director', 'admin']],
    ['GET', '/api/users/{member}', ['director', 'admin']],
    ['GET', '/api/users/{member}/roles', ['director', 'admin']],
    ['POST', '/api/users/add-role', ['director', 'admin']],
    ['GET', '/api/families/export', ['director', 'admin']],
    ['GET', '/api/families/import-template', ['director', 'admin']],
    ['POST', '/api/families/import', ['director', 'admin']],
    ['GET', '/api/families/trashed', ['director', 'admin']],
    ['GET', '/api/families/{family}/deletion-preview', ['director', 'admin']],
    ['DELETE', '/api/families/{family}', ['director', 'admin']],
    ['POST', '/api/families/{family}/restore', ['director', 'admin']],
    ['POST', '/api/families/{family}/purge', ['director', 'admin']],
    ['GET', '/api/families/{family}', ['director', 'admin', 'registar']],
    ['POST', '/api/families/{family}/comments', ['director', 'admin', 'registar']],
    ['GET', '/api/families/{family}/paiements', ['director', 'admin', 'registar']],
    ['POST', '/api/families/{family}/paiements/lignes', ['director', 'admin', 'registar']],
    ['POST', '/api/student-classrooms/enroll', ['director', 'admin', 'registar']],
    ['POST', '/api/student-classrooms/unenroll', ['director', 'admin', 'registar']],
    ['GET', '/api/cursus', ['director', 'admin']],
    ['POST', '/api/cursus', ['director', 'admin']],
    ['POST', '/api/classrooms', ['director', 'admin']],
    ['PUT', '/api/classrooms/{classroom}', ['director', 'admin']],
    ['DELETE', '/api/classrooms/{classroom}', ['director', 'admin']],
    ['GET', '/api/admin/classrooms', ['director', 'admin']],
    ['GET', '/api/admin/classrooms/{classroom}/suivi', ['director', 'admin']],
    ['GET', '/api/admin/outcomes', ['director', 'admin']],
    ['GET', '/api/schedules', ['director', 'admin']],
    ['GET', '/api/tarification/cursus', ['director', 'admin']],
    ['GET', '/api/statistics/overview', ['director', 'admin']],
    ['GET', '/api/teacher/classrooms', ['teacher']],
    // Routes fermées le 2026-10-09 : avant, tout membre de l'école y accédait,
    // professeur et rôle sans droit compris.
    ['GET', '/api/users/search?query=ma', ['director', 'admin', 'registar']],
    ['POST', '/api/families', ['director', 'admin', 'registar']],
    ['GET', '/api/classrooms', ['director', 'admin', 'registar']],
    ['GET', '/api/classrooms/{classroom}', ['director', 'admin', 'registar']],
]);

it('garde les mêmes accès pour chaque rôle par défaut', function (string $method, string $uri, array $allowed) {
    $uri = strtr($uri, [
        '{school}' => $this->school->id,
        '{year}' => $this->year->id,
        '{family}' => $this->family->id,
        '{classroom}' => $this->classroom->id,
        '{member}' => $this->member->id,
    ]);
    $payload = $uri === '/api/users/add-role'
        ? ['user_id' => $this->member->id, 'school_id' => $this->school->id, 'role' => 'registar']
        : [];

    foreach (PARITY_ROLES as $slug) {
        $user = parityUser($slug);
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::staffFor($this->school->id, $slug)->id,
            'roleable_type' => 'school',
            'roleable_id' => $this->school->id,
            'accepted_at' => now(),
        ]);

        $status = $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-School-Id' => (string) $this->school->id])
            ->json($method, $uri, $payload)
            ->baseResponse->getStatusCode();

        $denied = in_array($status, [401, 403], true);
        expect($denied)->toBe(!in_array($slug, $allowed, true), "{$slug} {$method} {$uri} → {$status}");
    }
})->with('routes');

it('ne laisse pas un admin nommer un autre admin', function () {
    $admin = parityUser('admin');
    UserRole::create([
        'user_id' => $admin->id,
        'role_id' => Role::staffFor($this->school->id, 'admin')->id,
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->postJson('/api/users/add-role', ['user_id' => $this->member->id, 'school_id' => $this->school->id, 'role' => 'admin'])
        ->assertForbidden();
});

it('refuse tout à un rôle personnalisé sans aucun droit', function (string $method, string $uri) {
    $role = Role::create(['school_id' => $this->school->id, 'name' => 'Bénévole', 'slug' => 'benevole']);
    $user = parityUser('benevole');
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ]);

    $uri = strtr($uri, [
        '{school}' => $this->school->id,
        '{year}' => $this->year->id,
        '{family}' => $this->family->id,
        '{classroom}' => $this->classroom->id,
        '{member}' => $this->member->id,
    ]);

    // Corps valide pour add-role : sa validation passe avant le contrôle des droits.
    $payload = $uri === '/api/users/add-role'
        ? ['user_id' => $this->member->id, 'school_id' => $this->school->id, 'role' => 'registar']
        : [];

    $status = $this->actingAs($user, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->json($method, $uri, $payload)
        ->baseResponse->getStatusCode();

    expect($status)->toBeIn([401, 403], "benevole {$method} {$uri} → {$status}");
})->with('routes');
