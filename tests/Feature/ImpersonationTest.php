<?php

use App\Models\Impersonation;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['toollab.super_admin_emails' => ['root@imp-test.com']]);
    $make = fn (string $email) => User::create([
        'first_name' => 'U', 'last_name' => $email, 'email' => $email,
        'password' => 'password', 'access' => true,
    ]);
    $this->admin = $make('root@imp-test.com');
    $this->target = $make('target@imp-test.com');
    $this->adminToken = $this->admin->createToken('new_token')->plainTextToken;

    $this->school = School::create(['name' => 'École Imp', 'address' => '1 rue', 'access' => true]);
    \App\Models\UserRole::create([
        'user_id' => $this->target->id,
        'role_id' => \App\Models\Role::where('slug', 'teacher')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
});

function startImpersonation($test, array $payload = ['reason' => 'Ticket #42']) {
    return $test->withToken($test->adminToken)
        ->postJson("/api/admin/users/{$test->target->id}/impersonate", $payload);
}

it("délivre un token au nom de la cible et trace la session", function () {
    $res = startImpersonation($this)->assertCreated()
        ->assertJsonPath('user.id', $this->target->id);

    $imp = Impersonation::sole();
    expect($imp->admin_id)->toBe($this->admin->id)
        ->and($imp->reason)->toBe('Ticket #42')
        ->and($this->target->fresh()->last_login_at)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($res->json('token'))->getJson("/api/users/{$this->target->id}")->assertOk();
});

it('exige un motif', function () {
    startImpersonation($this, [])->assertUnprocessable();
});

it("refuse un super-admin comme cible et un non super-admin comme initiateur", function () {
    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$this->admin->id}/impersonate", ['reason' => 'test'])
        ->assertForbidden();

    $token = $this->target->createToken('new_token')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson("/api/admin/users/{$this->admin->id}/impersonate", ['reason' => 'test'])
        ->assertForbidden();
});

it('bloque toute écriture et la comptabilise', function () {
    $token = startImpersonation($this)->json('token');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/users/change-password', ['password' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('impersonation_read_only', true);
    $this->withToken($token)
        ->postJson('/api/schools', ['name' => 'x'])
        ->assertForbidden();

    expect(Impersonation::sole()->blocked_writes)->toBe(2);
});

it('termine la session et révoque le token', function () {
    $token = startImpersonation($this)->json('token');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/impersonate/stop')->assertOk();

    expect(Impersonation::sole()->ended_at)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson("/api/users/{$this->target->id}")->assertUnauthorized();
});

it("n'autorise pas une impersonation imbriquée ni l'arrêt avec un token normal", function () {
    $this->withToken($this->adminToken)->postJson('/api/impersonate/stop')->assertStatus(400);
});

it("expose le journal d'audit au super-admin", function () {
    startImpersonation($this);
    $this->withToken($this->adminToken)->getJson('/api/admin/impersonations')
        ->assertOk()
        ->assertJsonPath('0.reason', 'Ticket #42')
        ->assertJsonPath('0.status', 'active')
        ->assertJsonPath('0.target.email', 'target@imp-test.com');
});

it("refuse un utilisateur hors staff (parent, invitation non acceptée)", function () {
    $parent = User::create([
        'first_name' => 'P', 'last_name' => 'Parent', 'email' => 'parent@imp-test.com',
        'password' => 'password', 'access' => true,
    ]);
    $family = new \App\Models\Family; $family->school_id = $this->school->id; $family->save();
    $attach = fn (string $slug, string $type, int $id, $accepted) => \App\Models\UserRole::create([
        'user_id' => $parent->id, 'role_id' => \App\Models\Role::where('slug', $slug)->value('id'),
        'roleable_type' => $type, 'roleable_id' => $id, 'accepted_at' => $accepted,
    ]);
    $attach('responsible', 'family', $family->id, null);
    $attach('teacher', 'school', $this->school->id, null); // invitation en attente

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$parent->id}/impersonate", ['reason' => 'test'])
        ->assertForbidden();
    expect(Impersonation::count())->toBe(0);
});
