<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['toollab.super_admin_emails' => ['root@disable-test.com']]);

    $this->school = School::factory()->create();
    $this->prof = User::factory()->create(['email' => 'prof@disable-test.com', 'password' => 'password', 'access' => true]);
    UserRole::create([
        'user_id' => $this->prof->id, 'role_id' => Role::where('slug', 'teacher')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
    $this->admin = User::factory()->create(['email' => 'root@disable-test.com', 'access' => true]);
});

function disableAs($test, User $actor, User $target, array $payload = ['reason' => 'Départ de l\'école'])
{
    app('auth')->forgetGuards();

    return $test->actingAs($actor, 'sanctum')->postJson("/api/admin/users/{$target->id}/disable", $payload);
}

it('désactive : sessions révoquées, connexion refusée, action tracée', function () {
    $session = $this->prof->createToken('navigateur')->plainTextToken;

    disableAs($this, $this->admin, $this->prof)->assertOk()->assertJsonPath('access', false);

    expect($this->prof->tokens()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withToken($session)->getJson('/api/schools')->assertUnauthorized();

    $this->postJson('/api/login', ['email' => 'prof@disable-test.com', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Votre compte a été désactivé. Contactez votre établissement ou le support Toollab.');

    expect(AuditLog::where('action', 'user.disabled')->sole()->meta)
        ->toBe(['reason' => "Départ de l'école", 'sessions_revoked' => 1]);
});

it("ne révèle pas l'état du compte sans le bon mot de passe", function () {
    disableAs($this, $this->admin, $this->prof);

    $this->postJson('/api/login', ['email' => 'prof@disable-test.com', 'password' => 'mauvais'])->assertUnauthorized();
});

it('réactive : la connexion refonctionne', function () {
    disableAs($this, $this->admin, $this->prof);
    app('auth')->forgetGuards();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->prof->id}/enable")
        ->assertOk()->assertJsonPath('disabled_reason', null);

    app('auth')->forgetGuards();
    $this->postJson('/api/login', ['email' => 'prof@disable-test.com', 'password' => 'password'])->assertCreated();
});

it("refuse de désactiver un super-admin, sans motif, ou par un non super-admin", function () {
    disableAs($this, $this->admin, $this->admin)->assertForbidden();
    disableAs($this, $this->admin, $this->prof, [])->assertUnprocessable();
    disableAs($this, $this->prof, $this->prof)->assertForbidden();

    expect($this->prof->fresh()->access)->toBeTrue();
});

it("n'autorise pas « voir en tant que » un compte désactivé", function () {
    disableAs($this, $this->admin, $this->prof);
    app('auth')->forgetGuards();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/users/{$this->prof->id}/impersonate", ['reason' => 'Ticket'])
        ->assertForbidden();
});
