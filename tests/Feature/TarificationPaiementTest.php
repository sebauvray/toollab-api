<?php

/*
|--------------------------------------------------------------------------
| Tarification et paiements
|--------------------------------------------------------------------------
|
| Montant dû d'une famille (tarif de base, réduction familiale, réduction
| multi-cursus, arrondi, snapshot figé à l'inscription), répartition encaissé
| / exonéré, et garde-fou anti-dépassement des règlements. Les inscriptions
| passent par la vraie route pour que le snapshot soit construit comme en prod.
|
*/

use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\ReductionFamiliale;
use App\Models\ReductionMultiCursus;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\Tarif;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PaiementService;
use App\Services\TarifCalculatorService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tarifCursus(string $name, int $prix): Cursus
{
    $cursus = Cursus::create(['name' => $name, 'progression' => 'continu']);
    Tarif::create(['cursus_id' => $cursus->id, 'prix' => $prix, 'actif' => true]);

    return $cursus;
}

function tarifClasse(Cursus $cursus, string $type = 'Children'): Classroom
{
    return Classroom::create([
        'name' => $cursus->name.' '.$type, 'years' => 2025, 'type' => $type, 'size' => 30, 'cursus_id' => $cursus->id,
    ]);
}

function tarifEleve(Family $family): User
{
    $student = User::factory()->create();
    UserRole::create([
        'user_id' => $student->id,
        'role_id' => Role::where('slug', 'student')->value('id'),
        'roleable_type' => 'family',
        'roleable_id' => $family->id,
    ]);

    return $student;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create();
    request()->attributes->set('current_school_id', $this->school->id);
    $this->year = SchoolYear::create(['label' => '2025-2026', 'is_active' => true, 'opened_at' => now()]);
    request()->attributes->set('current_school_year_id', $this->year->id);

    $this->director = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $this->director->id,
        'role_id' => Role::where('slug', 'director')->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ]);

    $this->headers = ['X-School-Id' => (string) $this->school->id];
    $this->family = Family::create();

    $this->inscrire = function (User $student, Classroom $classroom) {
        $this->actingAs($this->director)
            ->postJson('/api/student-classrooms/enroll', [
                'student_id' => $student->id,
                'classroom_id' => $classroom->id,
                'family_id' => $this->family->id,
            ], $this->headers)
            ->assertSuccessful();
    };

    $this->total = fn () => app(TarifCalculatorService::class)->calculerTotalFamille($this->family->fresh())['total'];

    $this->regler = fn (string $type, int $montant, array $extra = []) => $this->actingAs($this->director)
        ->postJson("/api/families/{$this->family->id}/paiements/lignes", ['type' => $type, 'montant' => $montant] + $extra, $this->headers);
});

it('facture le tarif de base d’un élève seul', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));

    expect(($this->total)())->toEqual(300);
});

it('applique la réduction familiale au palier le plus haut atteint', function () {
    $coran = tarifCursus('Coran', 300);
    ReductionFamiliale::create(['cursus_id' => $coran->id, 'nombre_eleves_min' => 2, 'pourcentage_reduction' => 10, 'actif' => true]);
    ReductionFamiliale::create(['cursus_id' => $coran->id, 'nombre_eleves_min' => 3, 'pourcentage_reduction' => 20, 'actif' => true]);
    $classe = tarifClasse($coran);

    ($this->inscrire)(tarifEleve($this->family), $classe);
    expect(($this->total)())->toEqual(300);

    ($this->inscrire)(tarifEleve($this->family), $classe);
    expect(($this->total)())->toEqual(540); // 2 × 270

    ($this->inscrire)(tarifEleve($this->family), $classe);
    expect(($this->total)())->toEqual(720); // 3 × 240
});

it('ignore une réduction inactive', function () {
    $coran = tarifCursus('Coran', 300);
    ReductionFamiliale::create(['cursus_id' => $coran->id, 'nombre_eleves_min' => 2, 'pourcentage_reduction' => 10, 'actif' => false]);
    $classe = tarifClasse($coran);

    ($this->inscrire)(tarifEleve($this->family), $classe);
    ($this->inscrire)(tarifEleve($this->family), $classe);

    expect(($this->total)())->toEqual(600);
});

it('applique la réduction multi-cursus au seul cursus bénéficiaire', function () {
    $coran = tarifCursus('Coran', 300);
    $arabe = tarifCursus('Arabe', 200);
    ReductionMultiCursus::create(['cursus_beneficiaire_id' => $arabe->id, 'cursus_requis_id' => $coran->id, 'pourcentage_reduction' => 25, 'actif' => true]);

    $eleve = tarifEleve($this->family);
    ($this->inscrire)($eleve, tarifClasse($coran));
    ($this->inscrire)($eleve, tarifClasse($arabe));

    expect(($this->total)())->toEqual(450); // 300 + 200 × 0,75
});

it('ne cumule pas les réductions : la plus forte l’emporte', function () {
    $coran = tarifCursus('Coran', 300);
    $arabe = tarifCursus('Arabe', 200);
    ReductionFamiliale::create(['cursus_id' => $arabe->id, 'nombre_eleves_min' => 2, 'pourcentage_reduction' => 10, 'actif' => true]);
    ReductionMultiCursus::create(['cursus_beneficiaire_id' => $arabe->id, 'cursus_requis_id' => $coran->id, 'pourcentage_reduction' => 25, 'actif' => true]);
    $classeArabe = tarifClasse($arabe);

    $a = tarifEleve($this->family);
    ($this->inscrire)($a, tarifClasse($coran));
    ($this->inscrire)($a, $classeArabe);
    ($this->inscrire)(tarifEleve($this->family), $classeArabe);

    // a : 300 + 200 × 0,75 (25 % > 10 %) ; b : 200 × 0,90
    expect(($this->total)())->toEqual(630);
});

it('arrondit chaque ligne à l’euro, au demi supérieur', function () {
    $coran = tarifCursus('Coran', 335);
    ReductionFamiliale::create(['cursus_id' => $coran->id, 'nombre_eleves_min' => 2, 'pourcentage_reduction' => 10, 'actif' => true]);
    $classe = tarifClasse($coran);

    ($this->inscrire)(tarifEleve($this->family), $classe);
    ($this->inscrire)(tarifEleve($this->family), $classe);

    expect(($this->total)())->toEqual(604); // 2 × round(301,5)
});

it('fige le tarif à l’inscription : une hausse ultérieure ne change pas le dû', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));

    Tarif::where('cursus_id', $coran->id)->update(['prix' => 500]);
    ReductionFamiliale::create(['cursus_id' => $coran->id, 'nombre_eleves_min' => 1, 'pourcentage_reduction' => 50, 'actif' => true]);

    expect(($this->total)())->toEqual(300);
});

it('retombe sur le tarif courant pour une inscription sans snapshot', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));
    DB::table('student_classrooms')->update(['tarif_snapshot' => null]);

    Tarif::where('cursus_id', $coran->id)->update(['prix' => 500]);

    expect(($this->total)())->toEqual(500);
});

it('ne facture pas une inscription inactive', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));
    DB::table('student_classrooms')->update(['status' => 'inactive']);

    expect(($this->total)())->toEqual(0);
});

it('distingue encaissé et exonéré dans le reste à payer', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));

    ($this->regler)('espece', 100)->assertSuccessful();
    ($this->regler)('cheque', 50, ['cheque' => ['banque' => 'BNP', 'numero' => '123', 'nom_emetteur' => 'X']])->assertSuccessful();
    ($this->regler)('exoneration', 30, ['justification' => 'Difficultés'])->assertSuccessful();

    $d = app(PaiementService::class)->getDetailsPaiement($this->family->fresh());

    expect($d['montant_total'])->toEqual(300)
        ->and($d['montant_paye'])->toEqual(180)
        ->and($d['montant_encaisse'])->toEqual(150)
        ->and($d['montant_exonere'])->toEqual(30)
        ->and($d['reste_a_payer'])->toEqual(120);
});

it('refuse un règlement qui dépasserait le montant dû', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));

    ($this->regler)('espece', 250)->assertSuccessful();
    ($this->regler)('espece', 51)->assertStatus(422);
    ($this->regler)('espece', 50)->assertSuccessful();

    expect(DB::table('lignes_paiement')->sum('montant'))->toEqual(300);
});

it('refuse de modifier une ligne au-delà du montant dû', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));
    ($this->regler)('espece', 100)->assertSuccessful();
    ($this->regler)('espece', 100)->assertSuccessful();
    $ligneId = DB::table('lignes_paiement')->value('id');

    $this->actingAs($this->director)
        ->putJson("/api/families/{$this->family->id}/paiements/lignes/{$ligneId}", ['montant' => 201], $this->headers)
        ->assertStatus(422);

    $this->actingAs($this->director)
        ->putJson("/api/families/{$this->family->id}/paiements/lignes/{$ligneId}", ['montant' => 200], $this->headers)
        ->assertSuccessful();

    expect(DB::table('lignes_paiement')->sum('montant'))->toEqual(300);
});

it('ne compte pas les règlements d’une autre année', function () {
    $coran = tarifCursus('Coran', 300);
    ($this->inscrire)(tarifEleve($this->family), tarifClasse($coran));
    ($this->regler)('espece', 100)->assertSuccessful();

    $ancienne = SchoolYear::create(['label' => '2024-2025', 'is_active' => false, 'opened_at' => now()->subYear(), 'closed_at' => now()->subMonth()]);
    $paiementId = DB::table('paiements')->insertGetId([
        'family_id' => $this->family->id, 'school_year_id' => $ancienne->id, 'created_by' => $this->director->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lignes_paiement')->insert([
        'paiement_id' => $paiementId, 'type_paiement' => 'espece', 'montant' => 999, 'created_at' => now(), 'updated_at' => now(),
    ]);

    request()->attributes->set('current_school_id', $this->school->id);
    request()->attributes->set('current_school_year_id', $this->year->id);

    expect(app(PaiementService::class)->getDetailsPaiement($this->family->fresh())['montant_paye'])->toEqual(100);
});
