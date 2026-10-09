<?php

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function roleMgmtMember(School $school, string $slug): User
{
    $user = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => Role::staffFor($school->id, $slug)->id,
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => now(),
    ]);

    return $user;
}

function roleMgmtCall(User $user, School $school, string $method, string $uri, array $payload = [])
{
    return test()->actingAs($user, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $school->id])
        ->json($method, $uri, $payload);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->school = School::factory()->create(['access' => true]);
    $year = new SchoolYear(['label' => '2025-2026', 'opened_at' => now(), 'is_active' => true]);
    $year->school_id = $this->school->id;
    $year->save();
    $this->director = roleMgmtMember($this->school, 'director');
});

it('liste les rôles de l\'école avec le catalogue de permissions', function () {
    $response = roleMgmtCall($this->director, $this->school, 'GET', '/api/roles')->assertOk();

    expect(collect($response->json('roles'))->pluck('slug')->all())->toBe(['director', 'admin', 'registar', 'teacher'])
        ->and($response->json('roles.0.is_locked'))->toBeTrue()
        ->and($response->json('roles.0.assignable'))->toBeFalse()
        ->and($response->json('roles.0.users_count'))->toBe(1)
        ->and(collect($response->json('permissions'))->pluck('key'))->toContain('roles.manage');
});

it('crée un rôle personnalisé qui donne réellement ses droits une fois attribué', function () {
    $role = roleMgmtCall($this->director, $this->school, 'POST', '/api/roles', [
        'name' => 'Trésorier',
        'description' => 'Suit les paiements',
        'permissions' => ['statistics.view', 'families.view'],
    ])->assertCreated()->json();

    expect($role['slug'])->toBe('tresorier')
        ->and($role['assignable'])->toBeTrue();

    $member = roleMgmtMember($this->school, 'teacher');
    roleMgmtCall($this->director, $this->school, 'POST', '/api/users/add-role', [
        'user_id' => $member->id, 'school_id' => $this->school->id, 'role' => 'tresorier',
    ])->assertCreated();

    roleMgmtCall($member, $this->school, 'GET', '/api/statistics/overview')->assertOk();
    roleMgmtCall($member, $this->school, 'GET', '/api/tarification/cursus')->assertForbidden();
});

it('compte un rôle personnalisé avec l\'espace professeur parmi les professeurs', function () {
    roleMgmtCall($this->director, $this->school, 'POST', '/api/roles', [
        'name' => 'Intervenant', 'permissions' => ['teaching.access'],
    ])->assertCreated();
    $member = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $member->id,
        'role_id' => Role::staffFor($this->school->id, 'intervenant')->id,
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ]);

    $ids = collect(roleMgmtCall($this->director, $this->school, 'GET', '/api/users/teachers')->assertOk()->json('data'))
        ->pluck('id');

    expect($ids)->toContain($member->id);
});

it('modifie les permissions d\'un rôle par défaut', function () {
    $registar = Role::staffFor($this->school->id, 'registar');

    roleMgmtCall($this->director, $this->school, 'PUT', "/api/roles/{$registar->id}", [
        'name' => 'Secrétariat', 'permissions' => ['families.view'],
    ])->assertOk()->assertJsonPath('name', 'Secrétariat');

    expect($registar->fresh()->permissions()->pluck('key')->all())->toBe(['families.view'])
        ->and($registar->fresh()->slug)->toBe('registar');
});

it('refuse de modifier le rôle Directeur', function () {
    $director = Role::staffFor($this->school->id, 'director');

    roleMgmtCall($this->director, $this->school, 'PUT', "/api/roles/{$director->id}", [
        'name' => 'Chef', 'permissions' => [],
    ])->assertForbidden();
});

it('refuse de supprimer un rôle par défaut ou encore attribué', function () {
    roleMgmtCall($this->director, $this->school, 'DELETE', '/api/roles/'.Role::staffFor($this->school->id, 'teacher')->id)
        ->assertStatus(422);

    $custom = roleMgmtCall($this->director, $this->school, 'POST', '/api/roles', ['name' => 'Bénévole', 'permissions' => []])->json();
    $member = roleMgmtMember($this->school, 'teacher');
    UserRole::create([
        'user_id' => $member->id, 'role_id' => $custom['id'], 'roleable_type' => 'school',
        'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);

    roleMgmtCall($this->director, $this->school, 'DELETE', "/api/roles/{$custom['id']}")->assertStatus(409);

    UserRole::where('role_id', $custom['id'])->forceDelete();
    roleMgmtCall($this->director, $this->school, 'DELETE', "/api/roles/{$custom['id']}")->assertNoContent();
});

it('ne touche pas aux rôles d\'une autre école', function () {
    $other = School::factory()->create();

    roleMgmtCall($this->director, $this->school, 'PUT', '/api/roles/'.Role::staffFor($other->id, 'admin')->id, [
        'name' => 'Pirate', 'permissions' => [],
    ])->assertNotFound();
});

it('réserve la gestion des rôles à roles.manage', function () {
    $admin = roleMgmtMember($this->school, 'admin');

    roleMgmtCall($admin, $this->school, 'GET', '/api/roles')->assertOk();
    roleMgmtCall($admin, $this->school, 'POST', '/api/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
    roleMgmtCall(roleMgmtMember($this->school, 'registar'), $this->school, 'GET', '/api/roles')->assertForbidden();
});

it('indique à un admin les rôles qu\'il peut attribuer', function () {
    $admin = roleMgmtMember($this->school, 'admin');

    $assignable = collect(roleMgmtCall($admin, $this->school, 'GET', '/api/roles')->json('roles'))
        ->filter(fn ($role) => $role['assignable'])
        ->pluck('slug')
        ->values()
        ->all();

    expect($assignable)->toBe(['registar', 'teacher']);
});

it('refuse un nom en double et une permission inconnue', function () {
    roleMgmtCall($this->director, $this->school, 'POST', '/api/roles', ['name' => 'Professeur', 'permissions' => []])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    roleMgmtCall($this->director, $this->school, 'POST', '/api/roles', ['name' => 'Y', 'permissions' => ['tout.faire']])
        ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
});
