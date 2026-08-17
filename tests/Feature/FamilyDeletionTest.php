<?php

use App\Http\Controllers\Api\FamilyDeletionController;
use App\Http\Controllers\Api\StudentClassroomController;
use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Models\Paiement;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\Tarif;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PaiementService;
use App\Services\TarifCalculatorService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function roleId(string $slug): int
{
    return Role::where('slug', $slug)->value('id');
}

function makeUser(string $first, string $last): User
{
    return User::create([
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower($first.'.'.$last.'@test.com'),
        'password' => 'password',
        'access' => true,
    ]);
}

/** Rattache $user à $family avec le rôle $slug. */
function attach(User $user, Family $family, string $slug): UserRole
{
    return UserRole::create([
        'user_id' => $user->id,
        'role_id' => roleId($slug),
        'roleable_type' => 'family',
        'roleable_id' => $family->id,
    ]);
}

function controller(): FamilyDeletionController
{
    return new FamilyDeletionController(new PaiementService(new TarifCalculatorService()));
}

/**
 * Met la famille du fixture dans l'état où la suppression est permise.
 *
 * Une famille qui porte une inscription ACTIVE ou un règlement sur l'année
 * courante est refusée en 409 (deletionBlockers). L'utilisateur doit d'abord
 * désinscrire ses élèves. On désactive donc les inscriptions plutôt que de les
 * supprimer : les lignes restent en base, et c'est bien destroy() qui les coupe.
 */
function unblock(): void
{
    StudentClassroom::query()->update(['status' => 'inactive']);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create();

    // Le contexte doit être posé avant toute création : school_id / school_year_id
    // ne sont pas fillable, ce sont les traits BelongsToSchool(Year) qui les
    // remplissent depuis currentSchoolId() / currentSchoolYearId().
    request()->attributes->set('current_school_id', $this->school->id);

    $this->year = SchoolYear::create([
        'label' => '2025-2026',
        'is_active' => true,
        'opened_at' => now(),
    ]);

    request()->attributes->set('current_school_year_id', $this->year->id);

    // Un admin d'école : callerCanAccessFamily() exige un rôle staff.
    $this->admin = makeUser('Amine', 'Admin');
    UserRole::create([
        'user_id' => $this->admin->id,
        'role_id' => roleId('admin'),
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ]);
    $this->actingAs($this->admin);

    $this->cursus = Cursus::create(['name' => 'Coran', 'progression' => 'continu']);
    Tarif::create(['cursus_id' => $this->cursus->id, 'prix' => 200, 'actif' => true]);

    $this->classroom = Classroom::create([
        'name' => 'Coran A',
        'years' => 2025,
        'type' => 'Children',
        'size' => 10,
        'cursus_id' => $this->cursus->id,
    ]);

    // Famille : 1 responsable + 2 élèves, les deux inscrits en classe.
    $this->family = Family::create();
    $this->parent = makeUser('Karim', 'Benali');
    attach($this->parent, $this->family, 'responsible');

    $this->students = collect(['Yacine', 'Sara'])->map(function (string $first) {
        $student = makeUser($first, 'Benali');
        attach($student, $this->family, 'student');

        StudentClassroom::create([
            'student_id' => $student->id,
            'classroom_id' => $this->classroom->id,
            'family_id' => $this->family->id,
            'status' => 'active',
            'enrollment_date' => now(),
        ]);

        UserRole::create([
            'user_id' => $student->id,
            'role_id' => roleId('student'),
            'roleable_type' => 'classroom',
            'roleable_id' => $this->classroom->id,
        ]);

        return $student;
    });
});

it('retire la famille, ses rattachements et ses inscriptions', function () {
    unblock();

    $response = controller()->destroy($this->family);

    expect($response->getStatusCode())->toBe(200);
    expect(Family::count())->toBe(0);
    expect(StudentClassroom::count())->toBe(0);
    expect(UserRole::where('roleable_type', 'family')->where('roleable_id', $this->family->id)->count())->toBe(0);
    expect(UserRole::where('roleable_type', 'classroom')->count())->toBe(0);

    // La place n'est pas « libérée par la suppression » : elle l'était déjà, la
    // désinscription étant un préalable obligatoire.
    expect($this->classroom->fresh()->student_count)->toBe(0);
    expect($this->classroom->fresh()->available_spots)->toBe(10);
});

it('conserve les comptes utilisateurs et masque sans détruire', function () {
    unblock();

    $usersBefore = User::count();

    controller()->destroy($this->family);

    expect(User::count())->toBe($usersBefore);

    // Les lignes ne sont pas détruites, seulement masquées.
    expect(DB::table('families')->count())->toBe(1);
    expect(DB::table('student_classrooms')->count())->toBe(2);
});

it('ne touche pas au rattachement d’un membre à une autre école', function () {
    unblock();

    // Karim est aussi responsable d'une famille dans une seconde école.
    $otherSchool = School::factory()->create();
    request()->attributes->set('current_school_id', $otherSchool->id);
    $otherFamily = Family::create();
    $otherLink = attach($this->parent, $otherFamily, 'responsible');
    request()->attributes->set('current_school_id', $this->school->id);

    controller()->destroy($this->family);

    expect(User::find($this->parent->id))->not->toBeNull();
    expect(UserRole::find($otherLink->id))->not->toBeNull();
    expect(UserRole::find($otherLink->id)->deleted_at)->toBeNull();
});

it('restaure la famille, ses membres et ses inscriptions', function () {
    unblock();

    controller()->destroy($this->family);
    expect(Family::count())->toBe(0);

    $response = controller()->restore($this->family->id);

    expect($response->getStatusCode())->toBe(200);
    expect(Family::count())->toBe(1);
    // Les inscriptions reviennent dans l'état où elles étaient au moment de la
    // suppression — inactives, puisque c'était la condition pour supprimer.
    expect(StudentClassroom::count())->toBe(2);
    expect(UserRole::where('roleable_type', 'family')->where('roleable_id', $this->family->id)->count())->toBe(3);
    expect(UserRole::where('roleable_type', 'classroom')->count())->toBe(2);
});

it('ne ressuscite pas un rattachement retiré avant la suppression', function () {
    unblock();

    // Sara avait été retirée de la famille la veille.
    $sara = $this->students->last();
    UserRole::where('user_id', $sara->id)
        ->where('roleable_type', 'family')
        ->update(['deleted_at' => now()->subDay()]);

    controller()->destroy($this->family);
    controller()->restore($this->family->id);

    expect(Family::count())->toBe(1);
    // Le responsable et Yacine reviennent, pas Sara.
    expect(UserRole::where('roleable_type', 'family')->where('roleable_id', $this->family->id)->count())->toBe(2);
    expect(UserRole::where('user_id', $sara->id)->where('roleable_type', 'family')->count())->toBe(0);
});

it('permet de réinscrire un élève dans la même classe après suppression', function () {
    unblock();

    $yacine = $this->students->first();

    controller()->destroy($this->family);

    // La famille repart de zéro (comportement par défaut : pas de restauration auto).
    $newFamily = Family::create();
    attach($yacine, $newFamily, 'student');

    $request = Request::create('/enroll', 'POST', [
        'student_id' => $yacine->id,
        'classroom_id' => $this->classroom->id,
        'family_id' => $newFamily->id,
    ]);
    $request->setUserResolver(fn () => $this->admin);

    $response = (new StudentClassroomController())->enroll($request);

    // Sans le withTrashed() dans enroll(), l'index UNIQUE(student_id, classroom_id)
    // ferait échouer l'INSERT sur la ligne supprimée.
    expect($response->getStatusCode())->toBe(200);

    $enrollment = StudentClassroom::where('student_id', $yacine->id)->first();
    expect($enrollment)->not->toBeNull();
    expect($enrollment->status)->toBe('active');
    expect($enrollment->family_id)->toBe($newFamily->id);
    expect(DB::table('student_classrooms')->where('student_id', $yacine->id)->count())->toBe(1);
});

it('prévisualise ce que la suppression implique', function () {
    $data = controller()->preview($this->family)->getData(true)['data'];

    expect($data['family_name'])->toBe('BENALI Karim');
    expect($data['students'])->toBe(2);
    expect($data['responsibles'])->toBe(1);
    expect($data['active_enrollments'])->toBe(2);
    expect($data['classrooms'])->toBe(['Coran A']);
    expect($data['freed_spots'])->toBe(2);
    expect($data['reste_a_payer'])->toBe(400); // 2 élèves × 200
    expect($data['shared_users'])->toBe(0);
});

it('reflète les règlements déjà encaissés', function () {
    $paiement = Paiement::create(['family_id' => $this->family->id, 'created_by' => $this->admin->id]);
    LignePaiement::create([
        'paiement_id' => $paiement->id,
        'type_paiement' => 'espece',
        'montant' => 100,
    ]);

    $data = controller()->preview($this->family)->getData(true)['data'];

    expect($data['montant_paye'])->toBe(100);
    expect($data['reste_a_payer'])->toBe(300);
});

it('signale un membre actif dans un autre établissement', function () {
    $otherSchool = School::factory()->create();
    request()->attributes->set('current_school_id', $otherSchool->id);
    $otherFamily = Family::create();
    attach($this->parent, $otherFamily, 'responsible');
    request()->attributes->set('current_school_id', $this->school->id);

    $data = controller()->preview($this->family)->getData(true)['data'];

    expect($data['shared_users'])->toBe(1);
});

it('refuse la suppression à un utilisateur sans droit sur la famille', function () {
    $intrus = makeUser('Intrus', 'Externe');
    $this->actingAs($intrus);

    $response = controller()->destroy($this->family);

    expect($response->getStatusCode())->toBe(403);
    expect(Family::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Garde-fous : activité sur l'année courante
|--------------------------------------------------------------------------
*/

it('refuse la suppression quand un élève est inscrit en classe', function () {
    $response = controller()->destroy($this->family);

    expect($response->getStatusCode())->toBe(409);
    expect(Family::count())->toBe(1);
    expect(StudentClassroom::count())->toBe(2);

    $blockers = $response->getData(true)['data']['blockers'];
    expect(array_column($blockers, 'code'))->toBe(['enrollments']);
    expect($blockers[0]['label'])->toBe('2 inscriptions actives en classe');
});

it('refuse la suppression quand un règlement est enregistré', function () {
    unblock();

    $paiement = Paiement::create(['family_id' => $this->family->id, 'created_by' => $this->admin->id]);
    LignePaiement::create([
        'paiement_id' => $paiement->id,
        'type_paiement' => 'espece',
        'montant' => 100,
    ]);

    $response = controller()->destroy($this->family);

    expect($response->getStatusCode())->toBe(409);
    expect(Family::count())->toBe(1);
    expect(array_column($response->getData(true)['data']['blockers'], 'code'))->toBe(['payments']);
});

it('compte l’exonération comme un règlement bloquant', function () {
    unblock();

    $paiement = Paiement::create(['family_id' => $this->family->id, 'created_by' => $this->admin->id]);
    LignePaiement::create([
        'paiement_id' => $paiement->id,
        'type_paiement' => 'exoneration',
        'montant' => 400,
        'details' => ['justification' => 'Bourse'],
    ]);

    expect(controller()->destroy($this->family)->getStatusCode())->toBe(409);
});

it('cumule les deux blocages dans la prévisualisation', function () {
    $paiement = Paiement::create(['family_id' => $this->family->id, 'created_by' => $this->admin->id]);
    LignePaiement::create([
        'paiement_id' => $paiement->id,
        'type_paiement' => 'carte',
        'montant' => 50,
    ]);

    $data = controller()->preview($this->family)->getData(true)['data'];

    expect($data['can_delete'])->toBeFalse();
    expect(array_column($data['blockers'], 'code'))->toBe(['enrollments', 'payments']);
});

it('autorise la suppression une fois les élèves désinscrits et sans règlement', function () {
    unblock();

    $data = controller()->preview($this->family)->getData(true)['data'];

    expect($data['can_delete'])->toBeTrue();
    expect($data['blockers'])->toBe([]);
    expect(controller()->destroy($this->family)->getStatusCode())->toBe(200);
});

it('ne bloque pas sur un règlement d’une année déjà clôturée', function () {
    unblock();

    // Le garde-fou ne regarde que l'année courante : Paiement porte
    // BelongsToSchoolYear, une écriture archivée ne doit pas figer la famille.
    $ancienneAnnee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $paiement = Paiement::create(['family_id' => $this->family->id, 'created_by' => $this->admin->id]);
    LignePaiement::create([
        'paiement_id' => $paiement->id,
        'type_paiement' => 'espece',
        'montant' => 200,
    ]);
    DB::table('paiements')->where('id', $paiement->id)->update(['school_year_id' => $ancienneAnnee->id]);

    expect(controller()->destroy($this->family)->getStatusCode())->toBe(200);

    // Et l'écriture archivée est bien conservée.
    expect(DB::table('lignes_paiement')->count())->toBe(1);
    expect(DB::table('paiements')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Cas limites
|--------------------------------------------------------------------------
*/

it('refuse une seconde suppression de la même famille', function () {
    unblock();

    controller()->destroy($this->family);

    // La famille n'est plus résolvable : en HTTP le binding renverrait 404.
    expect(Family::find($this->family->id))->toBeNull();
    expect(Family::onlyTrashed()->count())->toBe(1);
});

it('refuse de restaurer une famille qui n’a jamais été supprimée', function () {
    $response = controller()->restore($this->family->id);

    expect($response->getStatusCode())->toBe(404);
});

it('refuse une seconde restauration', function () {
    unblock();

    controller()->destroy($this->family);
    controller()->restore($this->family->id);

    $response = controller()->restore($this->family->id);

    expect($response->getStatusCode())->toBe(404);
    expect(Family::count())->toBe(1);
});

it('supprime une famille vide sans élève ni inscription', function () {
    $empty = Family::create();

    $preview = controller()->preview($empty)->getData(true)['data'];
    expect($preview['students'])->toBe(0);
    expect($preview['active_enrollments'])->toBe(0);
    expect($preview['classrooms'])->toBe([]);

    $response = controller()->destroy($empty);

    expect($response->getStatusCode())->toBe(200);
    expect(Family::find($empty->id))->toBeNull();
});

it('supprime une famille dont les inscriptions sont déjà inactives', function () {
    StudentClassroom::query()->update(['status' => 'inactive']);

    $response = controller()->destroy($this->family);

    expect($response->getStatusCode())->toBe(200);
    expect(StudentClassroom::count())->toBe(0);
});

it('n’affecte pas les autres familles de la même école', function () {
    unblock();

    $autre = Family::create();
    $eleve = makeUser('Lina', 'Haddad');
    attach($eleve, $autre, 'student');
    StudentClassroom::create([
        'student_id' => $eleve->id,
        'classroom_id' => $this->classroom->id,
        'family_id' => $autre->id,
        'status' => 'active',
        'enrollment_date' => now(),
    ]);

    controller()->destroy($this->family);

    expect(Family::count())->toBe(1);
    expect(Family::first()->id)->toBe($autre->id);
    expect(StudentClassroom::count())->toBe(1);
    expect($this->classroom->fresh()->student_count)->toBe(1);
});

it('permet de réinscrire dans une AUTRE classe après suppression', function () {
    unblock();

    $yacine = $this->students->first();
    $autreClasse = Classroom::create([
        'name' => 'Coran B',
        'years' => 2025,
        'type' => 'Children',
        'size' => 10,
        'cursus_id' => $this->cursus->id,
    ]);

    controller()->destroy($this->family);

    $newFamily = Family::create();
    attach($yacine, $newFamily, 'student');

    $request = Request::create('/enroll', 'POST', [
        'student_id' => $yacine->id,
        'classroom_id' => $autreClasse->id,
        'family_id' => $newFamily->id,
    ]);
    $request->setUserResolver(fn () => $this->admin);

    $response = (new StudentClassroomController())->enroll($request);

    expect($response->getStatusCode())->toBe(200);
    expect($autreClasse->fresh()->student_count)->toBe(1);
    expect($this->classroom->fresh()->student_count)->toBe(0);
});

it('ne dépasse plus la capacité d’une classe en restaurant une famille', function () {
    // Avant les garde-fous, restore() pouvait remettre des inscriptions ACTIVES
    // dans une classe dont les places avaient été reprises entre-temps, et faire
    // passer l'effectif au-dessus de la taille. Ce n'est plus atteignable : on ne
    // peut supprimer qu'une famille déjà désinscrite, donc la restauration ne
    // ramène que des inscriptions inactives, qui ne consomment aucune place.
    $petiteClasse = Classroom::create([
        'name' => 'Classe de 2',
        'years' => 2025,
        'type' => 'Children',
        'size' => 2,
        'cursus_id' => $this->cursus->id,
    ]);

    StudentClassroom::query()->update(['classroom_id' => $petiteClasse->id]);
    expect($petiteClasse->fresh()->student_count)->toBe(2);
    expect($petiteClasse->fresh()->isFull())->toBeTrue();

    unblock();
    controller()->destroy($this->family);
    expect($petiteClasse->fresh()->student_count)->toBe(0);

    // Deux nouveaux élèves prennent les places libérées.
    $autreFamille = Family::create();
    collect(['Ines', 'Omar'])->each(function (string $first) use ($autreFamille, $petiteClasse) {
        $eleve = makeUser($first, 'Nouveau');
        attach($eleve, $autreFamille, 'student');
        StudentClassroom::create([
            'student_id' => $eleve->id,
            'classroom_id' => $petiteClasse->id,
            'family_id' => $autreFamille->id,
            'status' => 'active',
            'enrollment_date' => now(),
        ]);
    });
    expect($petiteClasse->fresh()->student_count)->toBe(2);

    controller()->restore($this->family->id);

    // Les 2 inscriptions restaurées sont inactives : l'effectif reste à 2.
    expect(StudentClassroom::count())->toBe(4);
    expect($petiteClasse->fresh()->student_count)->toBe(2);
    expect($petiteClasse->fresh()->available_spots)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Historique : ce qui se passe quand on consulte une année déjà clôturée
|--------------------------------------------------------------------------
*/

/**
 * Crée une année close, une classe et une inscription qui lui appartiennent.
 *
 * school_year_id n'étant pas fillable (c'est le trait BelongsToSchoolYear qui le
 * remplit depuis le contexte), on force la valeur en SQL après coup — sinon les
 * lignes atterrissent dans l'année courante et le test ne teste rien.
 */
function makeArchivedYearWithEnrollment($test): array
{
    $annee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $classe = Classroom::create([
        'name' => 'Coran ancien',
        'years' => 2024,
        'type' => 'Children',
        'size' => 10,
        'cursus_id' => $test->cursus->id,
    ]);
    DB::table('classrooms')->where('id', $classe->id)->update(['school_year_id' => $annee->id]);

    $inscription = StudentClassroom::create([
        'student_id' => $test->students->first()->id,
        'classroom_id' => $classe->id,
        'family_id' => $test->family->id,
        'status' => 'active',
        'enrollment_date' => now()->subYear(),
    ]);
    DB::table('student_classrooms')->where('id', $inscription->id)->update(['school_year_id' => $annee->id]);

    return [$annee, $classe, $inscription];
}

it('laisse intactes les inscriptions des années déjà clôturées', function () {
    [$annee, $classe, $inscription] = makeArchivedYearWithEnrollment($this);
    unblock();

    controller()->destroy($this->family);

    // Les 2 inscriptions de l'année courante sont coupées…
    expect(DB::table('student_classrooms')->whereNotNull('deleted_at')->count())->toBe(2);
    // …celle de 2024-2025 ne l'est pas.
    expect(DB::table('student_classrooms')->where('id', $inscription->id)->value('deleted_at'))->toBeNull();
});

it('rend la famille visible quand on consulte une année clôturée avant la suppression', function () {
    [$annee] = makeArchivedYearWithEnrollment($this);
    unblock();

    controller()->destroy($this->family);

    // Sur l'année courante : masquée.
    expect(Family::find($this->family->id))->toBeNull();

    // On bascule sur l'année archivée : la famille était bien là à l'époque.
    request()->attributes->set('current_school_year_id', $annee->id);

    expect(Family::find($this->family->id))->not->toBeNull();
    expect(Family::count())->toBe(1);
});

it('affiche aussi les membres de la famille dans une année clôturée', function () {
    [$annee] = makeArchivedYearWithEnrollment($this);
    unblock();

    controller()->destroy($this->family);
    request()->attributes->set('current_school_year_id', $annee->id);

    $family = Family::find($this->family->id);

    expect($family->students()->count())->toBe(2);
    expect($family->responsibles()->count())->toBe(1);
    expect(UserRole::where('roleable_type', 'family')->where('roleable_id', $family->id)->count())->toBe(3);
});

it('garde la famille masquée dans une année clôturée APRÈS la suppression', function () {
    // La famille est supprimée pendant que l'année est encore ouverte : elle ne
    // doit pas réapparaître le jour où cette année sera clôturée.
    unblock();
    controller()->destroy($this->family);

    $this->year->update(['is_active' => false, 'closed_at' => now()->addMinute()]);
    request()->attributes->set('current_school_year_id', $this->year->id);
    request()->attributes->remove('current_school_year_closed_at_'.$this->year->id);

    expect(Family::find($this->family->id))->toBeNull();
});
