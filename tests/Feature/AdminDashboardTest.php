<?php

use App\Models\School;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['toollab.super_admin_emails' => ['root@admin-test.com']]);
    $this->admin = User::create([
        'first_name' => 'Root', 'last_name' => 'Admin', 'email' => 'root@admin-test.com',
        'password' => 'password', 'access' => true,
    ]);
    School::create(['name' => 'École Test', 'address' => '1 rue', 'access' => true]);
});

it('renvoie le tableau de bord au super-admin', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('kpis.schools_total', 1)
        ->assertJsonPath('schools.0.name', 'École Test')
        ->assertJsonPath('schools.0.alerts', ['inactive', 'onboarding', 'no_director'])
        ->assertJsonPath('system.database.ok', true)
        ->assertJsonStructure(['todo' => ['pending_invitations', 'unassigned_users', 'unassigned_users_count'], 'system' => ['queue']]);
});

it('refuse un utilisateur standard', function () {
    $user = User::create([
        'first_name' => 'Jo', 'last_name' => 'User', 'email' => 'jo@admin-test.com',
        'password' => 'password', 'access' => true,
    ]);

    $this->actingAs($user, 'sanctum')->getJson('/api/admin/dashboard')->assertForbidden();
});

it('recherche les utilisateurs par nom, email ou id', function () {
    $school = School::first();
    $prof = User::create([
        'first_name' => 'Amina', 'last_name' => 'Benali', 'email' => 'amina@admin-test.com',
        'password' => 'password', 'access' => true,
    ]);
    \App\Models\UserRole::create([
        'user_id' => $prof->id,
        'role_id' => \App\Models\Role::where('slug', 'teacher')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $school->id, 'accepted_at' => now(),
    ]);

    $get = fn (array $params) => $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users?'.http_build_query($params));

    $get(['q' => 'amina benali'])->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'amina@admin-test.com')
        ->assertJsonPath('data.0.memberships.0.school', 'École Test');
    $get(['q' => (string) $prof->id])->assertJsonPath('data.0.id', $prof->id);
    $get(['role' => 'teacher'])->assertJsonCount(1, 'data');
    $get(['school_id' => $school->id])->assertJsonCount(1, 'data');
    $get(['q' => 'inexistant'])->assertJsonCount(0, 'data');
});

it('enregistre last_login_at à la connexion', function () {
    $this->postJson('/api/login', ['email' => 'root@admin-test.com', 'password' => 'password'])->assertCreated();

    expect($this->admin->fresh()->last_login_at)->not->toBeNull();
});

it('filtre, trie et masque les emails techniques des élèves', function () {
    $school = School::first();
    $family = new \App\Models\Family; $family->school_id = $school->id; $family->save();
    $attach = fn (User $u, string $slug, string $type, int $id, $accepted = null) => \App\Models\UserRole::create([
        'user_id' => $u->id, 'role_id' => \App\Models\Role::where('slug', $slug)->value('id'),
        'roleable_type' => $type, 'roleable_id' => $id, 'accepted_at' => $accepted,
    ]);
    $mk = fn (string $first, string $email) => User::create([
        'first_name' => $first, 'last_name' => 'Test', 'email' => $email, 'password' => 'password', 'access' => true,
    ]);

    $parent = $mk('Parent', 'parent@admin-test.com');
    $attach($parent, 'responsible', 'family', $family->id);
    $eleve = $mk('Eleve', 'eleve.test.student.abc123@school.com');
    $attach($eleve, 'student', 'family', $family->id);
    $invite = $mk('Invite', 'invite@admin-test.com');
    $attach($invite, 'teacher', 'school', $school->id);
    $parent->forceFill(['last_login_at' => now()])->save();

    $get = fn (array $params) => $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users?'.http_build_query($params));

    $row = collect($get(['q' => 'Eleve', 'population' => 'families'])->json('data'))->first();
    expect($row['email'])->toBeNull()
        ->and($row['technical_account'])->toBeTrue()
        ->and($row['can_impersonate'])->toBeFalse();

    expect(collect($get(['status' => 'pending'])->json('data'))->pluck('id')->all())->toBe([$invite->id]);
    // Seul le staff compte : ni le parent, ni l'élève, ni le super-admin sans rôle comme « jamais connectés »
    expect($get(['status' => 'never_logged'])->json('data'))->toBe([]);
    // Population : équipes par défaut (super-admin + invité), familles à part
    expect(collect($get([])->json('data'))->pluck('id')->sort()->values()->all())->toBe([$this->admin->id, $invite->id]);
    expect(collect($get(['population' => 'families'])->json('data'))->pluck('id')->sort()->values()->all())->toBe([$parent->id, $eleve->id]);
    expect($get(['population' => 'all'])->json('total'))->toBe(4);
    expect($get(['sort' => 'last_login', 'population' => 'all'])->json('data.0.id'))->toBe($parent->id);
    expect($get(['sort' => 'name', 'dir' => 'asc', 'q' => 'Test', 'population' => 'all'])->json('data.0.last_name'))->toBe('Admin');

    $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/users/{$parent->id}")
        ->assertOk()
        ->assertJsonPath('families.0.id', $family->id)
        ->assertJsonCount(2, 'families.0.members')
        ->assertJsonPath('can_impersonate', false);
});

it('classe un ancien staff dans « sans affectation »', function () {
    $school = School::first();
    $ancien = User::create([
        'first_name' => 'Ancien', 'last_name' => 'Prof', 'email' => 'ancien@admin-test.com',
        'password' => 'password', 'access' => true,
    ]);
    $role = \App\Models\UserRole::create([
        'user_id' => $ancien->id, 'role_id' => \App\Models\Role::where('slug', 'teacher')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $school->id, 'accepted_at' => now(),
    ]);
    expect($ancien->fresh()->became_staff_at)->not->toBeNull();

    $role->forceDelete();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users?status=no_assignment')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ancien->id)
        ->assertJsonPath('data.0.is_former_staff', true);

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/dashboard')
        ->assertJsonPath('todo.unassigned_users_count', 1);
});
