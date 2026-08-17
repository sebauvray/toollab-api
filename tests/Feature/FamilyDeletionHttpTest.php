<?php

/*
|--------------------------------------------------------------------------
| Suppression de famille : niveau HTTP
|--------------------------------------------------------------------------
|
| FamilyDeletionTest appelle le contrôleur directement et court-circuite donc
| toute la pile de middlewares. Ce fichier passe par les vraies routes pour
| couvrir ce que le contrôleur ne garantit pas lui-même : authentification,
| contexte école (X-School-Id), contexte année, et le filtre de rôles.
|
*/

use App\Models\Classroom;
use App\Models\Cursus;
use App\Models\Family;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\Tarif;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function httpRoleId(string $slug): int
{
    return Role::where('slug', $slug)->value('id');
}

function httpUser(string $first, string $last): User
{
    return User::create([
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower($first.'.'.$last.'@http-test.com'),
        'password' => 'password',
        'access' => true,
    ]);
}

/**
 * Compte les familles vivantes SANS passer par Eloquent.
 *
 * Après un appel HTTP de test, request() est celle de l'appel qui vient d'avoir
 * lieu : currentSchoolId() vaut alors l'école de ce dernier appel (ou null s'il
 * n'y avait pas de header). Family::count() filtrerait donc sur un contexte qui
 * n'est pas celui qu'on veut vérifier.
 */
function famillesEnBase(): int
{
    return DB::table('families')->whereNull('deleted_at')->count();
}

/**
 * Rend la famille supprimable.
 *
 * Une inscription ACTIVE fait renvoyer 409 par deletionBlockers. On désactive en
 * SQL brut : après un appel HTTP de test, les global scopes Eloquent se calent
 * sur le contexte du dernier appel, pas sur celui du fixture.
 */
function httpDesinscrire(): void
{
    DB::table('student_classrooms')->update(['status' => 'inactive']);
}

/** Crée un utilisateur doté de $slug dans $school, invitation acceptée. */
function httpStaff(string $slug, School $school, string $first = null): User
{
    $user = httpUser($first ?? ucfirst($slug), 'Staff');

    UserRole::create([
        'user_id' => $user->id,
        'role_id' => httpRoleId($slug),
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => now(),
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create();
    request()->attributes->set('current_school_id', $this->school->id);

    $this->year = SchoolYear::create([
        'label' => '2025-2026',
        'is_active' => true,
        'opened_at' => now(),
    ]);
    request()->attributes->set('current_school_year_id', $this->year->id);

    $this->admin = httpStaff('admin', $this->school);
    $this->director = httpStaff('director', $this->school);
    $this->registar = httpStaff('registar', $this->school);

    $cursus = Cursus::create(['name' => 'Coran', 'progression' => 'continu']);
    Tarif::create(['cursus_id' => $cursus->id, 'prix' => 200, 'actif' => true]);

    $this->classroom = Classroom::create([
        'name' => 'Coran A',
        'years' => 2025,
        'type' => 'Children',
        'size' => 10,
        'cursus_id' => $cursus->id,
    ]);

    $this->family = Family::create();
    $this->student = $student = httpUser('Yacine', 'Benali');
    UserRole::create([
        'user_id' => $student->id,
        'role_id' => httpRoleId('student'),
        'roleable_type' => 'family',
        'roleable_id' => $this->family->id,
    ]);
    StudentClassroom::create([
        'student_id' => $student->id,
        'classroom_id' => $this->classroom->id,
        'family_id' => $this->family->id,
        'status' => 'active',
        'enrollment_date' => now(),
    ]);

    $this->headers = ['X-School-Id' => (string) $this->school->id];
});

/*
|--------------------------------------------------------------------------
| Authentification et contexte
|--------------------------------------------------------------------------
*/

it('rejette un appel non authentifié', function () {
    $this->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(401);

    expect(famillesEnBase())->toBe(1);
});

it('rejette un appel sans header X-School-Id', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}")
        ->assertStatus(400);

    expect(famillesEnBase())->toBe(1);
});

it('rejette un header X-School-Id non numérique', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], ['X-School-Id' => 'abc'])
        ->assertStatus(400);
});

it('rejette une école à laquelle l’utilisateur n’appartient pas', function () {
    $autreEcole = School::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], ['X-School-Id' => (string) $autreEcole->id])
        ->assertStatus(403);

    expect(famillesEnBase())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Isolation multi-tenant
|--------------------------------------------------------------------------
*/

it('ne laisse pas supprimer la famille d’une autre école, même en y étant admin', function () {
    // L'admin est membre des deux écoles, mais cible la famille de l'école A
    // avec le contexte de l'école B : le global scope doit la rendre invisible.
    $ecoleB = School::factory()->create();
    UserRole::create([
        'user_id' => $this->admin->id,
        'role_id' => httpRoleId('admin'),
        'roleable_type' => 'school',
        'roleable_id' => $ecoleB->id,
        'accepted_at' => now(),
    ]);

    // L'école B doit avoir une année active, sinon SchoolYearContext répond 409
    // avant même que le binding de route ne tente de résoudre la famille — et on
    // ne testerait plus l'isolation mais l'absence d'année.
    request()->attributes->set('current_school_id', $ecoleB->id);
    SchoolYear::create([
        'label' => '2025-2026',
        'is_active' => true,
        'opened_at' => now(),
    ]);
    request()->attributes->set('current_school_id', $this->school->id);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], ['X-School-Id' => (string) $ecoleB->id])
        ->assertStatus(404);

    expect(famillesEnBase())->toBe(1);
});

it('ne montre dans la corbeille que les familles de l’école courante', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    // Une école B, avec sa propre famille supprimée.
    $ecoleB = School::factory()->create();
    UserRole::create([
        'user_id' => $this->admin->id,
        'role_id' => httpRoleId('admin'),
        'roleable_type' => 'school',
        'roleable_id' => $ecoleB->id,
        'accepted_at' => now(),
    ]);
    request()->attributes->set('current_school_id', $ecoleB->id);
    $familleB = Family::create();
    Family::whereKey($familleB->id)->update(['deleted_at' => now()]);
    request()->attributes->set('current_school_id', $this->school->id);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $this->headers)
        ->assertStatus(200);

    $ids = collect($response->json('data.items'))->pluck('id')->all();
    expect($ids)->toBe([$this->family->id]);
});

/*
|--------------------------------------------------------------------------
| Filtre de rôles
|--------------------------------------------------------------------------
*/

it('autorise un directeur', function () {
    httpDesinscrire();

    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    expect(famillesEnBase())->toBe(0);
});

it('refuse un responsable des inscriptions', function () {
    $this->actingAs($this->registar, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(403);

    expect(famillesEnBase())->toBe(1);
});

it('refuse un professeur', function () {
    $teacher = httpStaff('teacher', $this->school);

    $this->actingAs($teacher, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(403);

    expect(famillesEnBase())->toBe(1);
});

it('refuse la prévisualisation à un rôle non autorisé', function () {
    $this->actingAs($this->registar, 'sanctum')
        ->getJson("/api/families/{$this->family->id}/deletion-preview", $this->headers)
        ->assertStatus(403);
});

it('refuse la restauration à un rôle non autorisé', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers);

    $this->actingAs($this->registar, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/restore", [], $this->headers)
        ->assertStatus(403);

    expect(famillesEnBase())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Garde-fous : activité sur l'année courante
|--------------------------------------------------------------------------
*/

it('renvoie 409 et les motifs quand la famille a une inscription active', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(409)
        ->assertJsonPath('data.blockers.0.code', 'enrollments');

    expect(famillesEnBase())->toBe(1);
});

it('annonce le blocage dans la prévisualisation', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/families/{$this->family->id}/deletion-preview", $this->headers)
        ->assertStatus(200)
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath('data.blockers.0.code', 'enrollments');
});

it('laisse supprimer après une désinscription faite par la vraie route', function () {
    // Le parcours réel de l'utilisateur : il désinscrit, puis il supprime.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/student-classrooms/unenroll', [
            'student_id' => $this->student->id,
            'classroom_id' => $this->classroom->id,
        ], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/families/{$this->family->id}/deletion-preview", $this->headers)
        ->assertStatus(200)
        ->assertJsonPath('data.can_delete', true);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    expect(famillesEnBase())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Année scolaire clôturée
|--------------------------------------------------------------------------
*/

it('refuse la suppression sur une année clôturée', function () {
    $this->year->update(['is_active' => false, 'closed_at' => now()]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(409)
        ->assertJson(['read_only' => true]);

    expect(famillesEnBase())->toBe(1);
});

it('refuse la restauration sur une année clôturée', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->year->update(['is_active' => false, 'closed_at' => now()]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/restore", [], $this->headers)
        ->assertStatus(409);
});

it('ne montre dans la corbeille que les suppressions de l’année consultée', function () {
    httpDesinscrire();

    // L'année courante a démarré il y a un mois.
    $this->year->update(['opened_at' => now()->subMonth()]);

    // Une famille supprimée bien avant l'ouverture de l'année courante.
    request()->attributes->set('current_school_id', $this->school->id);
    $ancienne = Family::create();
    DB::table('families')->where('id', $ancienne->id)->update(['deleted_at' => now()->subMonths(6)]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $ids = collect(
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/families/trashed', $this->headers)
            ->assertStatus(200)
            ->json('data.items')
    )->pluck('id')->all();

    expect($ids)->toBe([$this->family->id]);
    expect(DB::table('families')->whereNotNull('deleted_at')->count())->toBe(2);
});

it('n’affiche pas dans la corbeille une famille ressuscitée par l’année archivée', function () {
    httpDesinscrire();

    // Année déjà close AVANT la suppression : la famille y redevient visible
    // dans la liste. Elle ne doit alors pas apparaître aussi dans la corbeille.
    $ancienneAnnee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $headersArchive = $this->headers + ['X-School-Year-Id' => (string) $ancienneAnnee->id];

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $headersArchive)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $headersArchive)
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.items');
});

it('renvoie toute la corbeille sans plafond ni N+1', function () {
    // Garde-fou contre deux régressions : le retour d'un LIMIT (troncature muette,
    // l'utilisateur croirait voir toute la liste) et le retour du N+1 sur la
    // résolution des noms (2 requêtes par famille au lieu de 3 au total).
    request()->attributes->set('current_school_id', $this->school->id);
    $responsibleRole = httpRoleId('responsible');

    for ($i = 1; $i <= 120; $i++) {
        $famille = Family::create();

        // Une famille sur trois a deux responsables : le nom doit les joindre.
        foreach (range(1, $i % 3 === 0 ? 2 : 1) as $j) {
            $parent = User::create([
                'first_name' => "Prenom{$i}{$j}",
                'last_name' => "NOM{$i}",
                'email' => "corbeille{$i}-{$j}@http-test.com",
                'password' => 'password',
                'access' => true,
            ]);
            UserRole::create([
                'user_id' => $parent->id,
                'role_id' => $responsibleRole,
                'roleable_type' => 'family',
                'roleable_id' => $famille->id,
            ]);
        }

        $t = now()->subDays(120 - $i);
        DB::table('families')->where('id', $famille->id)->update(['deleted_at' => $t]);
        DB::table('user_roles')->where('roleable_id', $famille->id)
            ->where('roleable_type', 'family')->update(['deleted_at' => $t]);
    }

    $this->year->update(['opened_at' => now()->subYear()]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $items = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $this->headers)
        ->assertStatus(200)
        ->json('data.items');
    $requetes = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($items)->toHaveCount(120);
    expect($requetes)->toBeLessThan(20);

    // Les familles à deux responsables portent bien les deux noms.
    expect(collect($items)->filter(fn ($i) => str_contains($i['nom'], ','))->count())->toBe(40);
});

it('sort la famille de l’archive après une suppression définitive', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $this->headers)
        ->assertJsonCount(1, 'data.items');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)
        ->assertStatus(200);

    // Plus dans l'archive, plus dans la liste — mais toujours en base.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $this->headers)
        ->assertJsonCount(0, 'data.items');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers)
        ->assertJsonCount(0, 'data.items');

    expect(DB::table('families')->where('id', $this->family->id)->exists())->toBeTrue();
    expect(DB::table('families')->where('id', $this->family->id)->value('purged_at'))->not->toBeNull();
    expect(DB::table('families')->where('id', $this->family->id)->value('purged_by'))->toBe($this->admin->id);
});

it('refuse de restaurer une famille supprimée définitivement', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)->assertStatus(200);
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/restore", [], $this->headers)
        ->assertStatus(409);

    expect(famillesEnBase())->toBe(0);
});

it('refuse une seconde suppression définitive', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)->assertStatus(200);
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)
        ->assertStatus(409);
});

it('refuse la suppression définitive d’une famille jamais archivée', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)
        ->assertStatus(404);
});

it('refuse la suppression définitive à un rôle non autorisé', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)->assertStatus(200);

    $this->actingAs($this->registar, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)
        ->assertStatus(403);
});

it('refuse la suppression définitive sur une année clôturée', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)->assertStatus(200);

    $this->year->update(['is_active' => false, 'closed_at' => now()]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)
        ->assertStatus(409);
});

it('garde une famille supprimée définitivement visible dans l’historique', function () {
    httpDesinscrire();

    // Année close AVANT l'archivage : la règle « ce qui est clôturé ne bouge
    // pas » doit survivre à la suppression définitive.
    $ancienneAnnee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)->assertStatus(200);
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/purge", [], $this->headers)->assertStatus(200);

    $headersArchive = $this->headers + ['X-School-Year-Id' => (string) $ancienneAnnee->id];

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $headersArchive)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');

    // …mais l'archive de cette année-là ne la propose pas non plus.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $headersArchive)
        ->assertJsonCount(0, 'data.items');
});

it('autorise la consultation de la corbeille sur une année clôturée', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->year->update(['is_active' => false, 'closed_at' => now()]);

    // GET : le middleware ne bloque que les mutations.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families/trashed', $this->headers)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

/*
|--------------------------------------------------------------------------
| Effets observables sur le reste de l'application
|--------------------------------------------------------------------------
*/

it('fait disparaître la famille de la liste des familles', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers)
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.items');
});

it('fait disparaître la famille de la fiche détail', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/families/{$this->family->id}", $this->headers)
        ->assertStatus(404);
});

it('retire les élèves des statistiques dès la désinscription, et la suppression n’en ramène aucun', function () {
    $total = fn () => $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/statistics/overview', $this->headers)
        ->assertStatus(200)
        ->json('data.enrollments.total');

    expect($total())->toBe(1);

    // Les statistiques comptent les inscriptions ACTIVES : c'est la
    // désinscription — désormais préalable obligatoire — qui les fait baisser.
    httpDesinscrire();
    expect($total())->toBe(0);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    expect($total())->toBe(0);
});

it('montre encore la famille supprimée quand on bascule sur une année clôturée', function () {
    httpDesinscrire();

    $ancienneAnnee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    // Année courante : la famille a disparu.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers)
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.items');

    // Année 2024-2025, close avant la suppression : elle est toujours là.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers + ['X-School-Year-Id' => (string) $ancienneAnnee->id])
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

it('rouvre la fiche de la famille supprimée depuis une année clôturée', function () {
    httpDesinscrire();

    $ancienneAnnee = SchoolYear::create([
        'label' => '2024-2025',
        'is_active' => false,
        'opened_at' => now()->subYear(),
        'closed_at' => now()->subMonths(2),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/families/{$this->family->id}", $this->headers)
        ->assertStatus(404);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson(
            "/api/families/{$this->family->id}",
            $this->headers + ['X-School-Year-Id' => (string) $ancienneAnnee->id]
        )
        ->assertStatus(200);
});

it('rend la famille et ses inscriptions après restauration', function () {
    httpDesinscrire();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/families/{$this->family->id}", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/families/{$this->family->id}/restore", [], $this->headers)
        ->assertStatus(200);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/families', $this->headers)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.items');

    // L'inscription revient telle qu'elle était au moment de la suppression :
    // inactive. La restauration ne réinscrit pas l'élève en classe, elle ne
    // reprend donc aucune place — il faudra le réinscrire explicitement.
    expect(DB::table('student_classrooms')->whereNull('deleted_at')->count())->toBe(1);
    expect($this->classroom->fresh()->student_count)->toBe(0);
});
