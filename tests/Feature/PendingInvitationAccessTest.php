<?php

/*
|--------------------------------------------------------------------------
| Invitation staff en attente : aucun droit avant acceptation
|--------------------------------------------------------------------------
|
| SchoolContext laisse entrer un parent de l'école même si son invitation staff
| est en attente (accès via la famille). Les contrôles de rôle faits dans les
| contrôleurs doivent donc, comme CheckRole, ignorer les rôles non acceptés.
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

it('ne liste que sa propre famille tant que l\'invitation est en attente', function () {
    $ids = $this->actingAs($this->invited, 'sanctum')
        ->withHeaders($this->headers)
        ->getJson('/api/families')
        ->assertOk()
        ->json('data.items.*.id');

    expect($ids)->toContain($this->ownFamily->id)
        ->not->toContain($this->otherFamily->id);
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
