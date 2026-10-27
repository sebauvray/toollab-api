<?php

/*
|--------------------------------------------------------------------------
| Permissions par action
|--------------------------------------------------------------------------
|
| Chaque bloc du menu (Familles, Paiements, Cursus, Classes, Professeurs,
| Tarification, Statistiques, Espace professeur) a une permission par action,
| activable séparément. On vérifie, permission par permission, qu'elle ouvre
| son action et elle seule : avec la seule consultation du bloc, l'action
| reste refusée.
|
*/

use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SchoolRoleProvisioner;
use App\Support\PermissionCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->school = School::factory()->create(['access' => true]);
    request()->attributes->set('current_school_id', $this->school->id);

    $this->year = SchoolYear::create(['label' => '2025-2026', 'is_active' => true, 'opened_at' => now()]);
    request()->attributes->set('current_school_year_id', $this->year->id);

    $this->cursus = Cursus::create(['name' => 'Coran', 'progression' => 'continu']);
    $this->classroom = Classroom::create([
        'name' => 'Coran A', 'years' => 2025, 'type' => 'Children', 'size' => 10, 'cursus_id' => $this->cursus->id,
    ]);
    $this->family = Family::create();
    $this->member = User::factory()->create(['access' => true]);

    // Membre de l'équipe, cible des actions sur le personnel
    $this->colleague = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $this->colleague->id, 'role_id' => Role::staffFor($this->school->id, 'teacher')->id,
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
});

/** Membre de l'école avec un rôle personnalisé portant exactement $keys. */
function actionMember(School $school, array $keys): User
{
    static $n = 0;
    $n++;
    $role = Role::create(['school_id' => $school->id, 'name' => "Rôle {$n}", 'slug' => "role-{$n}"]);
    $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));

    $user = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $user->id, 'role_id' => $role->id, 'roleable_type' => 'school',
        'roleable_id' => $school->id, 'accepted_at' => now(),
    ]);

    return $user;
}

function actionStatus(User $user, string $method, string $uri, array $payload = []): int
{
    $test = test();
    $uri = strtr($uri, [
        '{family}' => $test->family->id,
        '{classroom}' => $test->classroom->id,
        '{cursus}' => $test->cursus->id,
        '{member}' => $test->member->id,
        '{colleague}' => $test->colleague->id,
    ]);
    $payload = array_map(fn ($value) => is_string($value) ? strtr($value, [
        '{school}' => (string) $test->school->id,
        '{colleague}' => (string) $test->colleague->id,
    ]) : $value, $payload);

    return $test->actingAs($user, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $test->school->id])
        ->json($method, $uri, $payload)
        ->baseResponse->getStatusCode();
}

/** Consultation de chaque bloc : [permission, route de lecture]. */
dataset('views', [
    'familles' => ['families.view', 'GET', '/api/families/{family}'],
    'paiements' => ['payments.view', 'GET', '/api/families/{family}/paiements'],
    'cursus' => ['cursus.view', 'GET', '/api/cursus'],
    'classes' => ['classrooms.view', 'GET', '/api/admin/classrooms'],
    'professeurs' => ['staff.view', 'GET', '/api/users/teachers'],
    'tarification' => ['tarification.view', 'GET', '/api/tarification/cursus'],
    'statistiques' => ['statistics.view', 'GET', '/api/statistics/overview'],
]);

/** Chaque action : [permission, méthode, route, corps]. */
dataset('actions', [
    'créer une famille' => ['families.create', 'POST', '/api/families', []],
    'modifier une famille' => ['families.update', 'POST', '/api/families/{family}/responsibles', []],
    'supprimer une famille' => ['families.delete', 'GET', '/api/families/trashed', []],
    'importer des familles' => ['families.import', 'GET', '/api/families/import-template', []],
    'exporter les élèves' => ['families.export', 'GET', '/api/families/export', []],
    'ajouter des élèves' => ['students.create', 'POST', '/api/families/{family}/students', []],
    'modifier un élève' => ['students.update', 'PUT', '/api/families/{family}/students/{member}', []],
    'retirer un élève' => ['students.delete', 'DELETE', '/api/families/{family}/students/{member}', []],
    'inscrire dans une classe' => ['enrollments.create', 'POST', '/api/student-classrooms/enroll', []],
    'désinscrire d\'une classe' => ['enrollments.delete', 'POST', '/api/student-classrooms/unenroll', []],
    'ajouter un commentaire' => ['comments.create', 'POST', '/api/families/{family}/comments', []],
    'enregistrer un paiement' => ['payments.create', 'POST', '/api/families/{family}/paiements/lignes', []],
    'créer un cursus' => ['cursus.create', 'POST', '/api/cursus', []],
    'modifier un cursus' => ['cursus.update', 'PUT', '/api/cursus/{cursus}', []],
    'supprimer un cursus' => ['cursus.delete', 'DELETE', '/api/cursus/{cursus}', []],
    'créer une classe' => ['classrooms.create', 'POST', '/api/classrooms', []],
    'modifier une classe' => ['classrooms.update', 'PUT', '/api/classrooms/{classroom}', []],
    'supprimer une classe' => ['classrooms.delete', 'DELETE', '/api/classrooms/{classroom}', []],
    'retirer un élève d\'une classe' => ['classrooms.remove_student', 'DELETE', '/api/admin/classrooms/{classroom}/students/{member}', []],
    'exporter les classes' => ['classrooms.export', 'GET', '/api/admin/classrooms/export', []],
    'inviter du personnel' => ['staff.invite', 'POST', '/api/users/create-staff', ['email' => 'nouveau@test.fr', 'role' => 'teacher', 'school_id' => '{school}']],
    'modifier un membre' => ['staff.update', 'PUT', '/api/users/{colleague}', ['first_name' => 'Nouveau', 'last_name' => 'Nom']],
    'attribuer des rôles' => ['staff.roles', 'POST', '/api/users/remove-role', ['user_id' => '{colleague}', 'school_id' => '{school}', 'role_name' => 'teacher']],
    'retirer de l\'établissement' => ['staff.remove', 'POST', '/api/users/remove-from-school', ['user_id' => '{colleague}', 'school_id' => '{school}']],
    'modifier les tarifs' => ['tarification.update', 'POST', '/api/tarification/cursus/{cursus}/tarif', []],
    'créer une réduction' => ['reductions.create', 'POST', '/api/tarification/cursus/{cursus}/reduction-familiale', []],
    'exporter les statistiques' => ['statistics.export', 'GET', '/api/statistics/export-payments', []],
]);

it('ouvre la consultation de chaque bloc', function (string $key, string $method, string $uri) {
    expect(actionStatus(actionMember($this->school, PermissionCatalog::normalize([$key])), $method, $uri))->not->toBeIn([401, 403])
        ->and(actionStatus(actionMember($this->school, []), $method, $uri))->toBeIn([401, 403]);
})->with('views');

it('ouvre une action avec sa seule permission, pas avec la consultation du bloc', function (string $key, string $method, string $uri, array $payload) {
    $module = collect(PermissionCatalog::MODULES)->first(fn ($m) => in_array($key, array_column($m['permissions'], 'key'), true));
    $viewOnly = actionMember($this->school, PermissionCatalog::normalize([$module['permissions'][0]['key']]));
    $allowed = actionMember($this->school, PermissionCatalog::normalize([$key]));

    // Refus d'abord : l'action autorisée peut modifier les données (suppression…)
    expect(actionStatus($viewOnly, $method, $uri, $payload))->toBe(403, "consultation seule : {$method} {$uri}")
        ->and(actionStatus($allowed, $method, $uri, $payload))->not->toBeIn([401, 403], "{$key} : {$method} {$uri}");
})->with('actions');

it('sépare la modification et la suppression d\'un paiement ou d\'une réduction', function () {
    $updater = actionMember($this->school, PermissionCatalog::normalize(['payments.update', 'reductions.update']));
    $deleter = actionMember($this->school, PermissionCatalog::normalize(['payments.delete', 'reductions.delete']));
    $family = $this->family->id;

    // Identifiants inexistants : 404 prouve qu'on a passé le contrôle d'accès
    expect(actionStatus($updater, 'PUT', "/api/families/{$family}/paiements/lignes/999999"))->not->toBe(403)
        ->and(actionStatus($updater, 'DELETE', "/api/families/{$family}/paiements/lignes/999999"))->toBe(403)
        ->and(actionStatus($deleter, 'DELETE', "/api/families/{$family}/paiements/lignes/999999"))->not->toBe(403)
        ->and(actionStatus($deleter, 'PUT', "/api/families/{$family}/paiements/lignes/999999"))->toBe(403)
        ->and(actionStatus($updater, 'PUT', '/api/tarification/reduction-familiale/999999'))->not->toBe(403)
        ->and(actionStatus($updater, 'DELETE', '/api/tarification/reduction-familiale/999999'))->toBe(403)
        ->and(actionStatus($deleter, 'DELETE', '/api/tarification/reduction-familiale/999999'))->not->toBe(403);
});

it('sépare l\'invitation et l\'attribution de rôles à un membre existant', function () {
    $inviter = actionMember($this->school, PermissionCatalog::normalize(['staff.invite']));
    $payload = ['email' => $this->colleague->email, 'role' => 'registar', 'school_id' => (string) $this->school->id];

    // Le collègue fait déjà partie de l'école : lui ajouter un rôle demande staff.roles
    expect(actionStatus($inviter, 'POST', '/api/users/create-staff', $payload))->toBe(403);
});

it('sépare l\'appel et la saisie des décisions dans l\'espace professeur', function () {
    $attendance = actionMember($this->school, PermissionCatalog::normalize(['teaching.attendance']));
    $outcomes = actionMember($this->school, PermissionCatalog::normalize(['teaching.outcomes']));
    foreach ([$attendance, $outcomes] as $teacher) {
        DB::table('class_schedules')->insert([
            'classroom_id' => $this->classroom->id, 'day' => 'Lundi', 'start_time' => '10:00', 'end_time' => '11:00',
            'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // Les décisions se saisissent par le professeur principal
    $this->classroom->forceFill(['main_teacher_id' => $outcomes->id])->save();
    $classroom = $this->classroom->id;

    expect(actionStatus($attendance, 'GET', '/api/teacher/classrooms'))->toBe(200)
        ->and(actionStatus($attendance, 'POST', "/api/teacher/classrooms/{$classroom}/attendance"))->not->toBeIn([401, 403])
        ->and(actionStatus($attendance, 'POST', "/api/teacher/classrooms/{$classroom}/outcomes"))->toBe(403)
        ->and(actionStatus($outcomes, 'POST', "/api/teacher/classrooms/{$classroom}/outcomes"))->not->toBeIn([401, 403])
        ->and(actionStatus($outcomes, 'POST', "/api/teacher/classrooms/{$classroom}/attendance"))->toBe(403);
});

it('ouvre paramètres, années scolaires et rôles avec leurs permissions', function () {
    $everything = actionMember($this->school, PermissionCatalog::assignable());
    $director = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $director->id, 'role_id' => Role::staffFor($this->school->id, 'director')->id,
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);

    foreach ([['PUT', "/api/schools/{$this->school->id}"], ['POST', '/api/school-years'], ['POST', '/api/roles']] as [$method, $uri]) {
        expect(actionStatus($everything, $method, $uri))->not->toBeIn([401, 403], "{$method} {$uri}")
            ->and(actionStatus($director, $method, $uri))->not->toBeIn([401, 403], "{$method} {$uri}");
    }
});

describe('normalisation', function () {
    it('ajoute la consultation du bloc et ce qu\'il requiert', function () {
        expect(PermissionCatalog::normalize(['cursus.delete']))->toEqualCanonicalizing(['cursus.delete', 'cursus.view'])
            ->and(PermissionCatalog::normalize(['payments.create']))->toEqualCanonicalizing(['payments.create', 'payments.view', 'families.view'])
            ->and(PermissionCatalog::normalize(['teaching.outcomes']))->toEqualCanonicalizing(['teaching.outcomes', 'teaching.access']);
    });

    it('enregistre un rôle avec la consultation ajoutée automatiquement', function () {
        $director = User::factory()->create(['access' => true]);
        UserRole::create([
            'user_id' => $director->id, 'role_id' => Role::staffFor($this->school->id, 'director')->id,
            'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
        ]);

        $role = $this->actingAs($director, 'sanctum')
            ->withHeaders(['X-School-Id' => (string) $this->school->id])
            ->postJson('/api/roles', ['name' => 'Pédagogie', 'permissions' => ['classrooms.update']])
            ->assertCreated()
            ->json();

        expect($role['permissions'])->toEqualCanonicalizing(['classrooms.update', 'classrooms.view']);
    });

    it('refuse d\'attribuer la gestion de l\'établissement à un rôle', function () {
        $director = User::factory()->create(['access' => true]);
        UserRole::create([
            'user_id' => $director->id, 'role_id' => Role::staffFor($this->school->id, 'director')->id,
            'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
        ]);

        $this->actingAs($director, 'sanctum')
            ->withHeaders(['X-School-Id' => (string) $this->school->id])
            ->postJson('/api/roles', ['name' => 'Sous-directeur', 'permissions' => ['school.manage']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    });
});

it('rend au rôle Directeur verrouillé toute permission du catalogue', function () {
    $director = Role::staffFor($this->school->id, 'director');
    $director->permissions()->detach(Permission::where('key', 'reductions.delete')->value('id'));

    app(SchoolRoleProvisioner::class)->syncPermissions();

    expect($director->permissions()->pluck('key')->sort()->values()->all())
        ->toBe(collect(PermissionCatalog::defaultsFor('director'))->sort()->values()->all());
});

it('convertit les anciennes permissions des rôles existants', function () {
    // Remet l'école dans l'état d'avant : anciennes clés sur un rôle personnalisé et sur Admin
    $old = ['families.edit', 'families.delete', 'enrollments.manage', 'classrooms.supervise', 'roles.manage', 'staff.manage'];
    foreach ($old as $key) {
        DB::table('permissions')->insertOrIgnore(['key' => $key, 'group' => 'Ancien', 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }
    $custom = Role::create(['school_id' => $this->school->id, 'name' => 'Ancien rôle', 'slug' => 'ancien-role']);
    $custom->permissions()->sync(Permission::whereIn('key', ['families.view', 'families.delete', 'classrooms.supervise'])->pluck('id'));
    $admin = Role::staffFor($this->school->id, 'admin');
    $admin->permissions()->sync(Permission::whereIn('key', ['families.edit', 'enrollments.manage', 'roles.manage', 'staff.manage'])->pluck('id'));

    (include database_path('migrations/2026_10_12_100000_simplify_permissions_by_menu_block.php'))->up();

    expect($custom->permissions()->pluck('key')->all())
        ->toEqualCanonicalizing(['families.view', 'families.delete', 'payments.view', 'classrooms.view', 'classrooms.export', 'classrooms.remove_student'])
        ->and($admin->permissions()->pluck('key')->all())
        ->toEqualCanonicalizing([
            'families.view', 'families.create', 'families.update', 'students.create', 'students.update', 'students.delete',
            'comments.create', 'enrollments.create', 'enrollments.delete',
            'staff.view', 'staff.invite', 'staff.update', 'staff.roles', 'staff.remove', 'roles.manage',
        ])
        ->and(Permission::whereIn('key', ['families.edit', 'staff.manage', 'enrollments.manage', 'classrooms.supervise'])->exists())->toBeFalse();
});

it('exige la désinscription pour remplacer une inscription existante', function (array $keys, int $status) {
    $caller = actionMember($this->school, PermissionCatalog::normalize($keys));
    $student = User::factory()->create();
    UserRole::create([
        'user_id' => $student->id, 'role_id' => Role::global()->where('slug', 'student')->value('id'),
        'roleable_type' => 'family', 'roleable_id' => $this->family->id,
    ]);
    $next = Classroom::create([
        'name' => 'Coran B', 'years' => 2025, 'type' => 'Children', 'size' => 10, 'cursus_id' => $this->cursus->id,
    ]);
    $old = \App\Models\StudentClassroom::create([
        'student_id' => $student->id, 'classroom_id' => $this->classroom->id, 'family_id' => $this->family->id,
        'status' => 'active', 'enrollment_date' => now(),
    ]);
    $classRole = UserRole::create([
        'user_id' => $student->id, 'role_id' => Role::global()->where('slug', 'student')->value('id'),
        'roleable_type' => 'classroom', 'roleable_id' => $this->classroom->id,
    ]);

    expect(actionStatus($caller, 'POST', '/api/student-classrooms/enroll', [
        'student_id' => $student->id, 'classroom_id' => $next->id, 'family_id' => $this->family->id,
    ]))->toBe($status);
    expect($old->fresh()->trashed())->toBe($status === 200)
        ->and(UserRole::find($classRole->id) !== null)->toBe($status !== 200)
        ->and(\App\Models\StudentClassroom::where('student_id', $student->id)->where('classroom_id', $next->id)->exists())->toBe($status === 200);
})->with([
    'création seule' => [['enrollments.create'], 403],
    'création et désinscription' => [['enrollments.create', 'enrollments.delete'], 200],
]);

it('autorise une première inscription avec le seul droit de création', function () {
    $caller = actionMember($this->school, PermissionCatalog::normalize(['enrollments.create']));
    $student = User::factory()->create();
    UserRole::create([
        'user_id' => $student->id, 'role_id' => Role::global()->where('slug', 'student')->value('id'),
        'roleable_type' => 'family', 'roleable_id' => $this->family->id,
    ]);
    expect(actionStatus($caller, 'POST', '/api/student-classrooms/enroll', [
        'student_id' => $student->id, 'classroom_id' => $this->classroom->id, 'family_id' => $this->family->id,
    ]))->toBe(200);
});

it('applique la hiérarchie sur les deux routes de retrait du personnel', function (string $targetRole, int $expected) {
    $caller = actionMember($this->school, PermissionCatalog::normalize(['staff.remove']));
    foreach (['POST', 'DELETE'] as $method) {
        $target = User::factory()->create(['access' => true]);
        $roleId = $targetRole === 'equal'
            ? UserRole::where('user_id', $caller->id)->where('roleable_type', 'school')->where('roleable_id', $this->school->id)->value('role_id')
            : Role::staffFor($this->school->id, $targetRole)->id;
        $assignment = UserRole::create([
            'user_id' => $target->id, 'role_id' => $roleId,
            'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
        ]);
        $status = actionStatus($caller, $method, $method === 'POST' ? '/api/users/remove-from-school' : "/api/users/{$target->id}", [
            'school_id' => $this->school->id, 'user_id' => $target->id,
        ]);
        expect($status)->toBe($expected === 200 && $method === 'DELETE' ? 204 : $expected)
            ->and(UserRole::withTrashed()->find($assignment->id) !== null)->toBe($expected !== 200);
    }
})->with(['supérieur' => ['admin', 403], 'égal' => ['equal', 403], 'inférieur' => ['teacher', 200]]);

it('ne réutilise pas le droit de retrait détenu dans une autre école', function () {
    $caller = actionMember($this->school, ['staff.view']);
    $other = School::factory()->create(['access' => true]);
    foreach ([$caller, $this->colleague] as $user) {
        UserRole::create([
            'user_id' => $user->id, 'role_id' => Role::staffFor($other->id, 'admin')->id,
            'roleable_type' => 'school', 'roleable_id' => $other->id, 'accepted_at' => now(),
        ]);
    }
    expect(actionStatus($caller, 'DELETE', "/api/users/{$this->colleague->id}"))->toBe(403)
        ->and(UserRole::where('user_id', $this->colleague->id)->where('roleable_id', $this->school->id)->exists())->toBeTrue();
});

it('ouvre le planning du personnel sans ouvrir la gestion des classes', function () {
    $caller = actionMember($this->school, ['staff.view']);
    expect(actionStatus($caller, 'GET', '/api/schedules'))->toBe(200)
        ->and(actionStatus($caller, 'GET', '/api/admin/classrooms'))->toBe(403)
        ->and(actionStatus(actionMember($this->school, []), 'GET', '/api/schedules'))->toBe(403);
});

it('donne le catalogue au gestionnaire des retraits sans ouvrir sa modification', function () {
    $caller = actionMember($this->school, PermissionCatalog::normalize(['staff.remove']));
    expect(actionStatus($caller, 'GET', '/api/roles'))->toBe(200)
        ->and(actionStatus($caller, 'POST', '/api/roles', ['name' => 'Interdit', 'permissions' => []]))->toBe(403);
});

it('permet de charger les choix et de créer une classe sans droit sur les cursus', function () {
    $caller = actionMember($this->school, PermissionCatalog::normalize(['classrooms.create']));
    expect(actionStatus($caller, 'GET', '/api/cursus'))->toBe(200)
        ->and(actionStatus($caller, 'GET', '/api/users/teachers'))->toBe(200)
        ->and(actionStatus($caller, 'POST', '/api/cursus', ['name' => 'Interdit', 'progression' => 'continu']))->toBe(403)
        ->and(actionStatus($caller, 'POST', '/api/classrooms', [
            'name' => 'Nouvelle classe', 'cursus_id' => $this->cursus->id, 'gender' => 'Mixte',
            'size' => 10, 'type' => 'Children', 'years' => 2025, 'school_id' => $this->school->id,
        ]))->toBe(201);
});


it('réserve la lecture et l’ajout des commentaires à leur permission commune', function (bool $allowed) {
    $user = actionMember($this->school, $allowed ? ['families.view', 'comments.create'] : ['families.view']);
    $this->family->comments()->create(['content' => 'Note confidentielle', 'user_id' => $this->member->id]);
    $this->actingAs($user, 'sanctum')->withHeaders(['X-School-Id' => (string) $this->school->id]);
    $response = $this->getJson('/api/families/'.$this->family->id)->assertOk();
    if ($allowed) {
        $response->assertJsonPath('data.family.comments.0.content', 'Note confidentielle');
    } else {
        $response->assertJsonPath('data.family.comments', [])->assertDontSee('Note confidentielle');
    }
    $response = $this->postJson('/api/families/'.$this->family->id.'/comments', ['content' => 'Nouvelle note']);
    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([false, true]);


it('restaure les droits de gestion des admins sans écraser leurs personnalisations', function () {
    $admin = Role::staffFor($this->school->id, 'admin');
    $admin->permissions()->sync(Permission::where('key', 'families.view')->pluck('id'));
    $migration = include database_path('migrations/2026_10_16_120000_restore_admin_management_permissions.php');
    $migration->up();
    $migration->up();
    expect($admin->permissions()->pluck('key')->all())->toEqualCanonicalizing([
        'families.view', 'school.settings.update', 'school_years.manage', 'roles.manage',
    ]);
});
