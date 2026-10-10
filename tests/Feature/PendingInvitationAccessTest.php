<?php

/*
|--------------------------------------------------------------------------
| Invitation staff en attente : aucun droit avant acceptation
|--------------------------------------------------------------------------
|
| L'outil est réservé au staff : seule une adhésion école acceptée ouvre une
| école. Un parent invité comme staff peut se connecter (pour accepter) mais
| ne voit rien tant qu'il n'a pas accepté ; un compte famille seul ne peut
| pas se connecter.
|
*/

use App\Models\Family;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pendingUser(string $first): User
{
    return User::create([
        'first_name' => $first,
        'last_name' => 'Pending',
        'email' => strtolower($first).'@pending-test.com',
        'password' => 'password',
        'access' => true,
    ]);
}

function pendingAttach(User $user, string $slug, string $type, int $id, bool $accepted): void
{
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => Role::where('slug', $slug)->value('id'),
        'roleable_type' => $type,
        'roleable_id' => $id,
        'accepted_at' => $accepted ? now() : null,
    ]);
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

    $this->ownFamily = Family::create();
    $this->otherFamily = Family::create();

    $this->otherParent = pendingUser('Autre');
    pendingAttach($this->otherParent, 'responsible', 'family', $this->otherFamily->id, false);

    // Parent de l'école, invité comme admin mais n'ayant pas encore accepté.
    $this->invited = pendingUser('Invite');
    pendingAttach($this->invited, 'responsible', 'family', $this->ownFamily->id, false);
    pendingAttach($this->invited, 'admin', 'school', $this->school->id, false);

    $this->headers = ['X-School-Id' => (string) $this->school->id];
});

it('n\'ouvre pas l\'école via la famille tant que l\'invitation est en attente', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson('/api/families')
        ->assertForbidden();

    $this->actingAs($this->invited, 'sanctum')
        ->getJson('/api/schools')
        ->assertOk()
        ->assertJsonCount(0);
});

it('laisse un invité se connecter pour accepter son invitation', function () {
    $this->postJson('/api/login', ['email' => $this->invited->email, 'password' => 'password'])
        ->assertCreated();
});

it('refuse la connexion à un compte famille sans rôle staff', function () {
    $this->postJson('/api/login', ['email' => $this->otherParent->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonMissingPath('token');

    expect($this->otherParent->tokens()->count())->toBe(0);
});

it('ferme l\'école à un compte famille déjà connecté', function () {
    $this->actingAs($this->otherParent, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson('/api/families')
        ->assertForbidden();
});

it('refuse l\'accès à une autre famille tant que l\'invitation est en attente', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson("/api/families/{$this->otherFamily->id}")
        ->assertForbidden();
});

it('refuse la fiche d\'un utilisateur de l\'école tant que l\'invitation est en attente', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->getJson("/api/users/{$this->otherParent->id}")
        ->assertForbidden();
});

it('refuse la liste des professeurs tant que l\'invitation est en attente', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson('/api/users/teachers')
        ->assertForbidden();
});

it('refuse d\'attribuer un rôle tant que l\'invitation est en attente', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->postJson('/api/users/add-role', [
            'user_id' => $this->otherParent->id,
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ])
        ->assertForbidden();
});

it('refuse l\'espace professeur tant que l\'invitation est en attente', function () {
    pendingAttach($this->invited, 'teacher', 'school', $this->school->id, false);

    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson('/api/teacher/classrooms')
        ->assertForbidden();
});

it('donne l\'accès une fois l\'invitation acceptée', function () {
    UserRole::where('user_id', $this->invited->id)
        ->where('roleable_type', 'school')
        ->update(['accepted_at' => now()]);

    $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson("/api/families/{$this->otherFamily->id}")
        ->assertOk();
});

it('laisse un ancien staff se connecter, sans aucune école', function () {
    $ancien = pendingUser('Ancien');
    $role = UserRole::create([
        'user_id' => $ancien->id, 'role_id' => Role::where('slug', 'teacher')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
    $role->forceDelete();

    $token = $this->postJson('/api/login', ['email' => $ancien->email, 'password' => 'password'])
        ->assertCreated()
        ->json('token');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/schools')->assertOk()->assertJsonCount(0);
    $this->withToken($token)->withHeaders($this->headers)->getJson('/api/families')->assertForbidden();
});

it('ne fait pas d\'un invité qui refuse un ancien staff', function () {
    $this->actingAs($this->invited, 'sanctum')
        ->postJson('/api/me/invitations/decline', ['school_id' => $this->school->id])
        ->assertSuccessful();

    expect($this->invited->fresh()->became_staff_at)->toBeNull();
    app('auth')->forgetGuards();
    $this->postJson('/api/login', ['email' => $this->invited->email, 'password' => 'password'])->assertForbidden();
});
