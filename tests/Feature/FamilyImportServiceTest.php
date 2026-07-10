<?php

use App\Models\Family;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserRole;
use App\Services\FamilyImportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const IMPORT_HEADER = [
    'Référence famille', 'Nom élève', 'Prénom élève', 'Date de naissance', 'Genre',
    'Nom responsable 1', 'Prénom responsable 1', 'Email responsable 1', 'Téléphone responsable 1',
    'Adresse responsable 1', 'Code postal responsable 1', 'Ville responsable 1',
    'Nom responsable 2', 'Prénom responsable 2', 'Email responsable 2', 'Téléphone responsable 2',
    'Adresse responsable 2', 'Code postal responsable 2', 'Ville responsable 2',
    'Élève est son propre responsable',
];

/** Écrit un CSV temporaire (délimiteur ;) et renvoie son chemin. */
function makeCsv(array $dataRows): string
{
    $lines = [implode(';', IMPORT_HEADER)];
    foreach ($dataRows as $row) {
        $row = array_pad($row, 20, '');
        $lines[] = implode(';', $row);
    }

    $path = tempnam(sys_get_temp_dir(), 'import_').'.csv';
    file_put_contents($path, implode("\n", $lines));

    return $path;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->school = School::factory()->create();
    request()->attributes->set('current_school_id', $this->school->id);
});

function resp1(): array
{
    return ['Benali', 'Karim', 'karim.benali@email.com', '0612345678', '12 rue des Lilas', '75011', 'Paris'];
}

function resp2(): array
{
    return ['Benali', 'Sofia', 'sofia.benali@email.com', '0698765432', '12 rue des Lilas', '75011', 'Paris'];
}

function respHaddad(): array
{
    return ['Haddad', 'Nadia', 'nadia.haddad@email.com', '0655443322', '5 av. Victor Hugo', '69003', 'Lyon'];
}

it('importe un fichier valide avec deux responsables et une fratrie', function () {
    // Responsable 1 répété sur chaque ligne de la fratrie (dédoublonné par email).
    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1(), resp2()),
        array_merge(['FAM001', 'Benali', 'Adam', '2017-09-05', 'M'], resp1(), resp2()),
        array_merge(['FAM002', 'Haddad', 'Lina', '2014-06-30', 'F'], respHaddad()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeTrue();
    expect($report['summary']['families'])->toBe(2);
    expect($report['summary']['students'])->toBe(3);
    expect($report['summary']['responsibles_created'])->toBe(3);

    expect(Family::count())->toBe(2);

    $studentRole = Role::where('slug', 'student')->first();
    expect(UserRole::where('role_id', $studentRole->id)->count())->toBe(3);

    $yasmine = User::where('first_name', 'Yasmine')->first();
    expect(UserInfo::where('user_id', $yasmine->id)->where('key', 'birthdate')->value('value'))->toBe('2015-03-12');
    expect(UserInfo::where('user_id', $yasmine->id)->where('key', 'gender')->value('value'))->toBe('F');

    $karim = User::where('email', 'karim.benali@email.com')->first();
    expect(UserInfo::where('user_id', $karim->id)->where('key', 'city')->value('value'))->toBe('Paris');
});

it('gère une famille de cinq élèves', function () {
    $rows = [
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1()),
        array_merge(['FAM001', 'Benali', 'Adam', '2017-09-05', 'M'], resp1()),
        array_merge(['FAM001', 'Benali', 'Sarah', '2012-01-20', 'F'], resp1()),
        array_merge(['FAM001', 'Benali', 'Rayan', '2019-11-08', 'M'], resp1()),
        array_merge(['FAM001', 'Benali', 'Nour', '2021-04-17', 'F'], resp1()),
    ];

    $report = (new FamilyImportService())->import(makeCsv($rows), 'test.csv');

    expect($report['ok'])->toBeTrue();
    expect($report['summary']['families'])->toBe(1);
    expect($report['summary']['students'])->toBe(5);
    expect($report['summary']['responsibles_created'])->toBe(1);

    $studentRole = Role::where('slug', 'student')->first();
    expect(UserRole::where('role_id', $studentRole->id)->count())->toBe(5);
});

it('gère un élève majeur qui est son propre responsable', function () {
    $row = ['FAM003', 'Cherif', 'Sofiane', '2003-08-25', 'M',
        'Cherif', 'Sofiane', 'sofiane.cherif@email.com', '0611223344', '18 rue Nationale', '59000', 'Lille',
        '', '', '', '', '', '', '', 'Oui'];

    $report = (new FamilyImportService())->import(makeCsv([$row]), 'test.csv');

    expect($report['ok'])->toBeTrue();
    expect($report['summary']['families'])->toBe(1);
    expect($report['summary']['students'])->toBe(1);
    expect($report['summary']['responsibles_created'])->toBe(1);

    // Un seul utilisateur, avec les DEUX rôles dans la même famille.
    $user = User::where('email', 'sofiane.cherif@email.com')->first();
    expect($user)->not->toBeNull();
    expect(User::count())->toBe(1);

    $family = Family::first();
    $roleSlugs = UserRole::where('user_id', $user->id)
        ->where('roleable_type', 'family')
        ->where('roleable_id', $family->id)
        ->with('role')
        ->get()
        ->pluck('role.slug')
        ->sort()
        ->values()
        ->all();
    expect($roleSlugs)->toBe(['responsible', 'student']);

    expect(UserInfo::where('user_id', $user->id)->where('key', 'birthdate')->value('value'))->toBe('2003-08-25');
    expect(UserInfo::where('user_id', $user->id)->where('key', 'city')->value('value'))->toBe('Lille');
});

it('rejette un genre invalide sans rien écrire', function () {
    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'X'], resp1()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(collect($report['errors'])->pluck('colonne'))->toContain('Genre');
    expect(Family::count())->toBe(0);
    expect(User::count())->toBe(0);
});

it('rejette une date de naissance invalide', function () {
    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '32/13/2015', 'F'], resp1()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(collect($report['errors'])->pluck('colonne'))->toContain('Date de naissance');
    expect(Family::count())->toBe(0);
});

it('rejette une ligne sans responsable 1', function () {
    $path = makeCsv([
        ['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'],
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(collect($report['errors'])->pluck('message')->implode(' '))->toContain('responsable 1');
    expect(Family::count())->toBe(0);
});

it('réutilise un responsable existant identifié par email', function () {
    $existing = User::create([
        'first_name' => 'Karim', 'last_name' => 'Benali',
        'email' => 'karim.benali@email.com',
        'password' => bcrypt('secret'), 'access' => true,
    ]);

    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeTrue();
    expect($report['summary']['responsibles_reused'])->toBe(1);
    expect($report['summary']['responsibles_created'])->toBe(0);
    expect(User::where('email', 'karim.benali@email.com')->count())->toBe(1);
    expect($existing->fresh()->id)->toBe($existing->id);
});

it('ne remplace pas les informations d un responsable existant', function () {
    $existing = User::create([
        'first_name' => 'Karim', 'last_name' => 'Benali',
        'email' => 'karim.benali@email.com',
        'password' => bcrypt('secret'), 'access' => true,
    ]);
    UserInfo::create(['user_id' => $existing->id, 'key' => 'city', 'value' => 'Ancienne ville']);
    UserInfo::create(['user_id' => $existing->id, 'key' => 'address', 'value' => 'Ancienne adresse']);

    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeTrue();
    expect(UserInfo::where('user_id', $existing->id)->where('key', 'city')->value('value'))->toBe('Ancienne ville');
    expect(UserInfo::where('user_id', $existing->id)->where('key', 'address')->value('value'))->toBe('Ancienne adresse');
});

it('rejette un élève déjà présent dans l école', function () {
    $studentRole = Role::where('slug', 'student')->first();
    $family = Family::create();
    $existing = User::create([
        'first_name' => 'Yasmine', 'last_name' => 'Benali',
        'email' => 'yasmine.benali.student.existing@school.com',
        'password' => bcrypt('secret'), 'access' => true,
    ]);
    UserInfo::create(['user_id' => $existing->id, 'key' => 'birthdate', 'value' => '2015-03-12']);
    $family->userRoles()->create(['user_id' => $existing->id, 'role_id' => $studentRole->id]);

    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1()),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(collect($report['errors'])->pluck('message')->implode(' '))->toContain('existe déjà');
    expect(Family::count())->toBe(1);
});

it('rejette un fichier trop volumineux au lieu de l importer partiellement', function () {
    $rows = [];
    for ($i = 1; $i <= 5001; $i++) {
        $rows[] = array_merge(['FAM'.$i, 'Benali', 'Yasmine'.$i, '2015-03-12', 'F'], resp1());
    }

    $report = (new FamilyImportService())->import(makeCsv($rows), 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(collect($report['errors'])->pluck('message')->implode(' '))->toContain('dépasse la limite');
    expect(Family::count())->toBe(0);
});

it('applique le tout-ou-rien : une ligne invalide annule tout le fichier', function () {
    $path = makeCsv([
        array_merge(['FAM001', 'Benali', 'Yasmine', '2015-03-12', 'F'], resp1()),
        array_merge(['FAM002', 'Haddad', 'Lina', '2014-06-30', 'Z'],
            ['Haddad', 'Nadia', 'nadia.haddad@email.com', '0655443322', '5 av. Victor Hugo', '69003', 'Lyon']),
    ]);

    $report = (new FamilyImportService())->import($path, 'test.csv');

    expect($report['ok'])->toBeFalse();
    expect(Family::count())->toBe(0);
    expect(User::count())->toBe(0);
});
