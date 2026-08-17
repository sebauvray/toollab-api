<?php

/*
|--------------------------------------------------------------------------
| Retrait d'un élève d'une famille
|--------------------------------------------------------------------------
|
| DELETE /api/families/{family}/students/{student} détruisait le compte User,
| TOUS ses user_roles (toutes écoles, toutes années) et ses user_infos. Les
| inscriptions, décisions et émargements des années déjà clôturées partaient
| en cascade : un élève retiré cette année disparaissait rétroactivement des
| classes des années précédentes.
|
| La règle est désormais la même que pour la suppression de famille :
| ce qui est clôturé ne bouge pas.
|
*/

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function removalRoleId(string $slug): int
{
    return Role::where('slug', $slug)->value('id');
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create();
    request()->attributes->set('current_school_id', $this->school->id);

    $this->anneeClose = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);
    $this->annee = SchoolYear::create([
        'label' => '2025-2026',
        'is_active' => true,
        'opened_at' => now()->subMonth(),
    ]);
    request()->attributes->set('current_school_year_id', $this->annee->id);

    $this->admin = User::create([
        'first_name' => 'Amine', 'last_name' => 'Admin',
        'email' => 'admin@removal.test', 'password' => 'password', 'access' => true,
    ]);
    UserRole::create([
        'user_id' => $this->admin->id, 'role_id' => removalRoleId('admin'),
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);

    $cursus = Cursus::create(['name' => 'Arabe', 'progression' => 'levels']);

    $this->classeAncienne = Classroom::create([
        'name' => 'Ancienne', 'years' => 2024, 'type' => 'Arabe', 'size' => 10, 'cursus_id' => $cursus->id,
    ]);
    DB::table('classrooms')->where('id', $this->classeAncienne->id)
        ->update(['school_year_id' => $this->anneeClose->id]);

    $this->classeCourante = Classroom::create([
        'name' => 'Courante', 'years' => 2025, 'type' => 'Arabe', 'size' => 10, 'cursus_id' => $cursus->id,
    ]);

    $this->family = Family::create([]);

    $this->parent = User::create([
        'first_name' => 'Céline', 'last_name' => 'Marchand',
        'email' => 'celine@removal.test', 'password' => 'password', 'access' => true,
    ]);
    UserRole::create([
        'user_id' => $this->parent->id, 'role_id' => removalRoleId('responsible'),
        'roleable_type' => 'family', 'roleable_id' => $this->family->id,
    ]);

    $this->student = User::create([
        'first_name' => 'François', 'last_name' => 'Marchand',
        'email' => 'francois@removal.test', 'password' => 'password', 'access' => true,
    ]);
    UserRole::create([
        'user_id' => $this->student->id, 'role_id' => removalRoleId('student'),
        'roleable_type' => 'family', 'roleable_id' => $this->family->id,
    ]);
    UserInfo::create(['user_id' => $this->student->id, 'key' => 'birthdate', 'value' => '2013-04-02']);

    // Inscrit les DEUX années.
    $ancienne = StudentClassroom::create([
        'student_id' => $this->student->id, 'classroom_id' => $this->classeAncienne->id,
        'family_id' => $this->family->id, 'status' => 'active', 'enrollment_date' => now()->subYear(),
    ]);
    DB::table('student_classrooms')->where('id', $ancienne->id)
        ->update(['school_year_id' => $this->anneeClose->id]);
    $this->inscriptionAncienne = $ancienne->id;

    UserRole::create([
        'user_id' => $this->student->id, 'role_id' => removalRoleId('student'),
        'roleable_type' => 'classroom', 'roleable_id' => $this->classeAncienne->id,
    ]);

    StudentClassroom::create([
        'student_id' => $this->student->id, 'classroom_id' => $this->classeCourante->id,
        'family_id' => $this->family->id, 'status' => 'active', 'enrollment_date' => now(),
    ]);
    UserRole::create([
        'user_id' => $this->student->id, 'role_id' => removalRoleId('student'),
        'roleable_type' => 'classroom', 'roleable_id' => $this->classeCourante->id,
    ]);

    // Une décision et un émargement dans l'année clôturée.
    StudentYearOutcome::create([
        'student_id' => $this->student->id, 'school_year_id' => $this->anneeClose->id,
        'classroom_id' => $this->classeAncienne->id, 'outcome' => 'passage',
    ]);
    request()->attributes->set('current_school_year_id', $this->anneeClose->id);
    Attendance::create([
        'student_id' => $this->student->id, 'classroom_id' => $this->classeAncienne->id,
        'date' => now()->subMonths(6)->toDateString(), 'status' => 'present',
    ]);
    request()->attributes->set('current_school_year_id', $this->annee->id);

    $this->headers = ['X-School-Id' => (string) $this->school->id];

    $this->retirer = fn () => $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}/students/{$this->student->id}", [], $this->headers);
});

it('ne détruit plus le compte de l’élève', function () {
    ($this->retirer)()->assertStatus(200);

    expect(User::find($this->student->id))->not->toBeNull();
    expect(UserInfo::where('user_id', $this->student->id)->count())->toBe(1);
});

it('laisse intacte l’inscription de l’année clôturée', function () {
    ($this->retirer)()->assertStatus(200);

    expect(DB::table('student_classrooms')->where('id', $this->inscriptionAncienne)->value('deleted_at'))
        ->toBeNull();
});

it('laisse l’élève dans la classe de l’année clôturée', function () {
    ($this->retirer)()->assertStatus(200);

    $suivi = $this->actingAs($this->admin, 'sanctum')
        ->getJson(
            "/api/admin/classrooms/{$this->classeAncienne->id}/suivi",
            $this->headers + ['X-School-Year-Id' => (string) $this->anneeClose->id]
        )->assertStatus(200);

    expect(collect($suivi->json('data.students'))->pluck('first_name')->all())->toBe(['François']);
});

it('conserve la décision et l’émargement de l’année clôturée', function () {
    ($this->retirer)()->assertStatus(200);

    expect(StudentYearOutcome::where('student_id', $this->student->id)->count())->toBe(1);
    expect(Attendance::withoutGlobalScopes()->where('student_id', $this->student->id)->count())->toBe(1);
});

it('retire bien l’élève de l’année en cours', function () {
    ($this->retirer)()->assertStatus(200);

    // Plus d'inscription vivante sur l'année courante…
    expect(StudentClassroom::where('family_id', $this->family->id)->count())->toBe(0);
    expect($this->classeCourante->fresh()->student_count)->toBe(0);

    // …et l'élève ne figure plus sur la fiche famille.
    $fiche = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/families/{$this->family->id}", $this->headers)->assertStatus(200);

    expect($fiche->json('data.family.students'))->toBe([]);
});

it('ne touche pas aux rôles de l’élève dans une autre école', function () {
    $autreEcole = School::factory()->create();
    request()->attributes->set('current_school_id', $autreEcole->id);
    $autreFamille = Family::create([]);
    $autreLien = UserRole::create([
        'user_id' => $this->student->id, 'role_id' => removalRoleId('student'),
        'roleable_type' => 'family', 'roleable_id' => $autreFamille->id,
    ]);
    request()->attributes->set('current_school_id', $this->school->id);

    ($this->retirer)()->assertStatus(200);

    expect(DB::table('user_roles')->where('id', $autreLien->id)->value('deleted_at'))->toBeNull();
});

it('conserve le rôle de responsable quand l’élève est aussi responsable', function () {
    $doubleRole = UserRole::create([
        'user_id' => $this->student->id, 'role_id' => removalRoleId('responsible'),
        'roleable_type' => 'family', 'roleable_id' => $this->family->id,
    ]);

    ($this->retirer)()->assertStatus(200);

    expect(DB::table('user_roles')->where('id', $doubleRole->id)->value('deleted_at'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| La décision de fin d'année suit l'inscription
|--------------------------------------------------------------------------
|
| Sans ce nettoyage, la décision restait en base, invisible partout, faussait
| le compteur de /decisions, et RESSURGISSAIT telle quelle si l'élève était
| réinscrit plus tard dans la même classe.
|
*/

it('retire la décision de l’année courante avec l’élève', function () {
    StudentYearOutcome::create([
        'student_id' => $this->student->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'passage',
        'commentaire' => 'Très bon niveau',
    ]);

    ($this->retirer)()->assertStatus(200);

    expect(StudentYearOutcome::where('school_year_id', $this->annee->id)
        ->where('student_id', $this->student->id)->count())->toBe(0);

    // Celle de l'année clôturée, elle, est intacte (posée dans le beforeEach).
    expect(StudentYearOutcome::where('school_year_id', $this->anneeClose->id)
        ->where('student_id', $this->student->id)->count())->toBe(1);
});

it('ne fait pas ressurgir une ancienne décision à la réinscription', function () {
    StudentYearOutcome::create([
        'student_id' => $this->student->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'exclusion',
    ]);

    ($this->retirer)()->assertStatus(200);

    // On remet l'élève dans la famille puis dans la même classe.
    UserRole::withTrashed()
        ->where('user_id', $this->student->id)
        ->where('roleable_type', 'family')
        ->update(['deleted_at' => null]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/student-classrooms/enroll', [
            'student_id' => $this->student->id,
            'classroom_id' => $this->classeCourante->id,
            'family_id' => $this->family->id,
        ], $this->headers)->assertStatus(200);

    $vue = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/outcomes', $this->headers)->assertStatus(200);

    expect(collect($vue->json('data.items'))->pluck('outcome')->filter()->all())->toBe([]);
});

it('retire aussi la décision lors d’une désinscription de classe', function () {
    StudentYearOutcome::create([
        'student_id' => $this->student->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'redoublement',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/student-classrooms/unenroll', [
            'student_id' => $this->student->id,
            'classroom_id' => $this->classeCourante->id,
        ], $this->headers)->assertStatus(200);

    expect(StudentYearOutcome::where('school_year_id', $this->annee->id)->count())->toBe(0);
    expect(StudentYearOutcome::where('school_year_id', $this->anneeClose->id)->count())->toBe(1);
});

it('retire aussi la décision lors d’un retrait depuis la vue classe', function () {
    StudentYearOutcome::create([
        'student_id' => $this->student->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'fin_cursus',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson(
            "/api/admin/classrooms/{$this->classeCourante->id}/students/{$this->student->id}",
            [],
            $this->headers
        )->assertStatus(200);

    expect(StudentYearOutcome::where('school_year_id', $this->annee->id)->count())->toBe(0);
    expect(StudentYearOutcome::where('school_year_id', $this->anneeClose->id)->count())->toBe(1);
});

it('ne touche pas à la décision d’un autre élève de la même classe', function () {
    $autre = User::create([
        'first_name' => 'Autre', 'last_name' => 'Eleve',
        'email' => 'autre@removal.test', 'password' => 'password', 'access' => true,
    ]);
    StudentClassroom::create([
        'student_id' => $autre->id, 'classroom_id' => $this->classeCourante->id,
        'family_id' => $this->family->id, 'status' => 'active', 'enrollment_date' => now(),
    ]);
    StudentYearOutcome::create([
        'student_id' => $autre->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'passage',
    ]);
    StudentYearOutcome::create([
        'student_id' => $this->student->id,
        'school_year_id' => $this->annee->id,
        'classroom_id' => $this->classeCourante->id,
        'outcome' => 'passage',
    ]);

    ($this->retirer)()->assertStatus(200);

    expect(StudentYearOutcome::where('student_id', $autre->id)->count())->toBe(1);
    expect(StudentYearOutcome::where('student_id', $this->student->id)
        ->where('school_year_id', $this->annee->id)->count())->toBe(0);
});

it('refuse un élève qui n’appartient pas à la famille', function () {
    $intrus = User::create([
        'first_name' => 'Intrus', 'last_name' => 'Externe',
        'email' => 'intrus@removal.test', 'password' => 'password', 'access' => true,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}/students/{$intrus->id}", [], $this->headers)
        ->assertStatus(404);

    expect(User::find($intrus->id))->not->toBeNull();
});

it('refuse sur une année scolaire clôturée', function () {
    $this->annee->update(['is_active' => false, 'closed_at' => now()]);

    ($this->retirer)()->assertStatus(409);

    expect(StudentClassroom::withoutGlobalScopes()->where('family_id', $this->family->id)
        ->whereNull('deleted_at')->count())->toBe(2);
});
