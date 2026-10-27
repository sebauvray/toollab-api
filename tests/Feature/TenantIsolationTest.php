<?php

/*
|--------------------------------------------------------------------------
| Isolation entre écoles
|--------------------------------------------------------------------------
|
| Deux écoles complètes (année, cursus, tarif, réductions, classe, famille,
| inscription, paiement, ligne, décision). On vérifie que chaque modèle
| métier ne voit que les lignes de l'école courante, y compris les modèles
| sans school_id (isolés via l'année ou via leur parent), puis que les routes
| refusent les identifiants d'une autre école.
|
*/

use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Models\Paiement;
use App\Models\ReductionFamiliale;
use App\Models\ReductionMultiCursus;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use App\Models\Tarif;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Place la requête courante dans le contexte donné (null = absent). */
function isolationContext(?int $schoolId, ?int $yearId): void
{
    foreach (['current_school_id' => $schoolId, 'current_school_year_id' => $yearId] as $key => $value) {
        $value === null
            ? request()->attributes->remove($key)
            : request()->attributes->set($key, $value);
    }
}

/** Crée une école et toutes ses données métier, dans son propre contexte. */
function isolationSchool(string $name): array
{
    $school = School::factory()->create(['name' => $name]);
    isolationContext($school->id, null);

    $year = SchoolYear::create(['label' => '2025-2026', 'is_active' => true, 'opened_at' => now()]);
    isolationContext($school->id, $year->id);

    $director = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $director->id,
        'role_id' => Role::where('slug', 'director')->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => now(),
    ]);

    $cursus = Cursus::create(['name' => "Coran {$name}", 'progression' => 'continu']);
    $tarif = Tarif::create(['cursus_id' => $cursus->id, 'prix' => 200, 'actif' => true]);
    ReductionFamiliale::create(['cursus_id' => $cursus->id, 'nombre_eleves_min' => 2, 'pourcentage_reduction' => 10, 'actif' => true]);
    ReductionMultiCursus::create(['cursus_beneficiaire_id' => $cursus->id, 'cursus_requis_id' => $cursus->id, 'pourcentage_reduction' => 10, 'actif' => true]);

    $classroom = Classroom::create([
        'name' => "Classe {$name}", 'years' => 2025, 'type' => 'Children', 'size' => 10, 'cursus_id' => $cursus->id,
    ]);

    $family = Family::create();
    $student = User::factory()->create();
    UserRole::create([
        'user_id' => $student->id,
        'role_id' => Role::where('slug', 'student')->value('id'),
        'roleable_type' => 'family',
        'roleable_id' => $family->id,
    ]);
    StudentClassroom::create([
        'student_id' => $student->id, 'classroom_id' => $classroom->id, 'family_id' => $family->id,
        'status' => 'active', 'enrollment_date' => now(),
    ]);

    $paiement = Paiement::create(['family_id' => $family->id, 'created_by' => $director->id]);
    $ligne = LignePaiement::create(['paiement_id' => $paiement->id, 'type_paiement' => 'espece', 'montant' => 50]);

    StudentYearOutcome::create([
        'student_id' => $student->id, 'school_year_id' => $year->id, 'classroom_id' => $classroom->id,
        'outcome' => 'passage', 'decided_by' => $director->id, 'decided_at' => now(),
    ]);

    return compact('school', 'year', 'director', 'cursus', 'tarif', 'classroom', 'family', 'student', 'paiement', 'ligne');
}

/** Modèles métier dont chaque école possède exactement une ligne. */
function isolatedModels(): array
{
    return [
        Family::class, Classroom::class, Cursus::class, Tarif::class, ReductionFamiliale::class,
        ReductionMultiCursus::class, StudentClassroom::class, Paiement::class, LignePaiement::class,
        StudentYearOutcome::class,
    ];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->a = isolationSchool('A');
    $this->b = isolationSchool('B');
});

it('ne montre que les données de l’école et de l’année courantes', function () {
    isolationContext($this->a['school']->id, $this->a['year']->id);

    foreach (isolatedModels() as $model) {
        expect($model::count())->toBe(1, "{$model} devrait n'avoir qu'une ligne visible");
    }
    expect(Paiement::find($this->b['paiement']->id))->toBeNull()
        ->and(LignePaiement::find($this->b['ligne']->id))->toBeNull()
        ->and(StudentClassroom::where('family_id', $this->b['family']->id)->exists())->toBeFalse();
});

it('isole aussi les modèles sans school_id quand aucune année n’est résolue', function () {
    // Cas des routes « school » hors middleware schoolyear
    isolationContext($this->a['school']->id, null);

    foreach ([Tarif::class, ReductionFamiliale::class, ReductionMultiCursus::class, StudentClassroom::class,
        Paiement::class, LignePaiement::class, StudentYearOutcome::class] as $model) {
        expect($model::count())->toBe(1, "{$model} fuit vers l'autre école sans année courante");
    }
});

it('ne renvoie rien des modèles à school_id sans école courante', function () {
    isolationContext(null, null);

    expect(Family::count())->toBe(0)
        ->and(Classroom::count())->toBe(0)
        ->and(Cursus::count())->toBe(0);
});

it('laisse tout visible hors contexte HTTP (console, jobs en file)', function () {
    // Comportement voulu : les jobs n'ont ni école ni année résolues.
    isolationContext(null, null);

    expect(Paiement::count())->toBe(2)
        ->and(LignePaiement::count())->toBe(2)
        ->and(StudentYearOutcome::count())->toBe(2);
});

it('refuse le header X-School-Id d’une école dont on n’est pas membre', function () {
    $this->actingAs($this->a['director'])
        ->getJson('/api/families', ['X-School-Id' => (string) $this->b['school']->id])
        ->assertForbidden();
});

it('renvoie 404 sur les ressources d’une autre école', function (string $method, string $uri) {
    $replace = [
        '{familyB}' => $this->b['family']->id,
        '{classroomB}' => $this->b['classroom']->id,
        '{familyA}' => $this->a['family']->id,
        '{ligneB}' => $this->b['ligne']->id,
    ];

    $this->actingAs($this->a['director'])
        ->json($method, strtr($uri, $replace), ['montant' => 1], ['X-School-Id' => (string) $this->a['school']->id])
        ->assertNotFound();
})->with([
    'fiche famille' => ['GET', '/api/families/{familyB}'],
    'paiements famille' => ['GET', '/api/families/{familyB}/paiements'],
    'facture famille' => ['GET', '/api/families/{familyB}/paiements/facture'],
    'fiche classe' => ['GET', '/api/classrooms/{classroomB}'],
    'ligne via sa propre famille' => ['PUT', '/api/families/{familyA}/paiements/lignes/{ligneB}'],
    'suppression ligne' => ['DELETE', '/api/families/{familyA}/paiements/lignes/{ligneB}'],
]);

it('ne modifie pas les données de l’autre école', function () {
    $this->actingAs($this->a['director'])
        ->putJson(
            "/api/families/{$this->a['family']->id}/paiements/lignes/{$this->b['ligne']->id}",
            ['montant' => 1],
            ['X-School-Id' => (string) $this->a['school']->id],
        );

    expect(DB::table('lignes_paiement')->where('id', $this->b['ligne']->id)->value('montant'))->toBe(50);
});
