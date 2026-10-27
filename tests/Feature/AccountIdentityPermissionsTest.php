<?php

use App\Models\{School, SchoolYear, User, UserRole, Role, Permission, Family};
use App\Support\PermissionCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->school = School::factory()->create(['access' => true]);
    $this->other = School::factory()->create(['access' => true]);
    request()->attributes->set('current_school_id', $this->school->id);
    SchoolYear::create(['label' => '2026-2027', 'is_active' => true, 'opened_at' => now()]);
    $this->family = Family::create();
    $this->target = User::factory()->create(['access' => true]);
    UserRole::create(['user_id' => $this->target->id, 'role_id' => Role::global()->where('slug', 'responsible')->value('id'), 'roleable_type' => 'family', 'roleable_id' => $this->family->id]);
    $this->caller = User::factory()->create(['access' => true]);
    $role = Role::create(['school_id' => $this->school->id, 'name' => 'Secrétariat', 'slug' => 'secretariat']);
    $role->permissions()->sync(Permission::whereIn('key', PermissionCatalog::normalize(['staff.update', 'families.update']))->pluck('id'));
    identityGrant($this->caller, $this->school, $role);
    $this->actingAs($this->caller, 'sanctum')->withHeaders(['X-School-Id' => (string) $this->school->id]);
});

function identityGrant(User $user, School $school, Role $role, bool $accepted = true): void
{
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'roleable_type' => 'school', 'roleable_id' => $school->id, 'accepted_at' => $accepted ? now() : null]);
}

function identityParentPayload(User $user, string $email): array
{
    return ['firstname' => $user->first_name, 'lastname' => $user->last_name, 'email' => $email,
        'phone' => '0600000000', 'address' => '1 rue Test', 'zipcode' => '75001', 'city' => 'Paris', 'is_student' => false];
}

it('interdit au secrétariat de modifier un directeur ou administrateur', function (string $slug) {
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, $slug));
    $email = $this->target->email;
    $this->putJson('/api/users/'.$this->target->id, ['first_name' => 'Changé', 'last_name' => 'Nom', 'email' => 'attaquant@example.test'])->assertForbidden();
    expect($this->target->fresh()->email)->toBe($email);
})->with(['director', 'admin']);

it('autorise le profil inférieur mais pas son email de connexion', function () {
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'teacher'));
    $payload = ['first_name' => 'Nouveau', 'last_name' => 'Nom', 'email' => $this->target->email];
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertOk();
    $payload['email'] = 'attaquant@example.test';
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($this->target->fresh()->email)->toBe($this->target->email);
});

it('protège le parent membre du personnel dans une autre école, même invité ou ancien', function (string $state) {
    if ($state === 'former') {
        $this->target->forceFill(['became_staff_at' => now()])->save();
    } else {
        identityGrant($this->target, $this->other, Role::staffFor($this->other->id, $state === 'teacher' ? 'teacher' : 'director'), $state !== 'pending');
    }
    $uri = '/api/families/'.$this->family->id.'/responsible/'.$this->target->id;
    $this->putJson($uri, identityParentPayload($this->target, 'attaquant@example.test'))->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($this->target->fresh()->email)->toBe($this->target->email);
    $this->putJson($uri, identityParentPayload($this->target, $this->target->email))->assertOk();
    $this->getJson('/api/families/'.$this->family->id)->assertOk()->assertJsonFragment(['email_editable' => false]);
    $this->postJson('/api/forgot-password', ['email' => 'attaquant@example.test'])->assertOk();
    Notification::assertNothingSent();
})->with(['director', 'teacher', 'pending', 'former']);

it('conserve la modification de contact des comptes uniquement familiaux', function () {
    $this->putJson('/api/families/'.$this->family->id.'/responsible/'.$this->target->id,
        identityParentPayload($this->target, 'nouveau@example.test'))->assertOk();
    expect($this->target->fresh()->email)->toBe('nouveau@example.test')->and($this->target->fresh()->canLogIn())->toBeFalse();
});

it('ne transforme pas un compte familial en super administrateur par son email', function () {
    config(['toollab.super_admin_emails' => ['reserve@example.test']]);
    $this->putJson('/api/families/'.$this->family->id.'/responsible/'.$this->target->id,
        identityParentPayload($this->target, 'reserve@example.test'))->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('réserve aussi le changement de son propre email hors des formulaires de profil', function () {
    $this->putJson('/api/users/'.$this->caller->id, ['first_name' => 'Moi', 'last_name' => 'Nom'])->assertOk();
    $this->putJson('/api/users/'.$this->caller->id, ['first_name' => 'Moi', 'last_name' => 'Nom', 'email' => 'nouveau@example.test'])->assertUnprocessable();
});

it('ne réutilise pas staff.update obtenu dans une autre école', function () {
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'teacher'));
    identityGrant($this->target, $this->other, Role::staffFor($this->other->id, 'teacher'));
    $caller = User::factory()->create(['access' => true]);
    identityGrant($caller, $this->school, Role::staffFor($this->school->id, 'teacher'));
    identityGrant($caller, $this->other, Role::staffFor($this->other->id, 'director'));
    $this->actingAs($caller, 'sanctum')->putJson('/api/users/'.$this->target->id, ['first_name' => 'Changé', 'last_name' => 'Nom'])->assertForbidden();
});

it('refuse un profil de mêmes droits et prend en compte tous les rôles de la cible', function () {
    $callerRole = UserRole::where('user_id', $this->caller->id)->where('roleable_type', 'school')->first()->role;
    identityGrant($this->target, $this->school, $callerRole);
    $payload = ['first_name' => 'Changé', 'last_name' => 'Nom'];
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertForbidden();
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'teacher'));
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertForbidden();
});

it('un directeur ne change pas l’email d’un professeur partagé entre écoles', function () {
    identityGrant($this->caller, $this->school, Role::staffFor($this->school->id, 'director'));
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'teacher'));
    identityGrant($this->target, $this->other, Role::staffFor($this->other->id, 'director'));
    $payload = ['first_name' => 'Nouveau', 'last_name' => 'Nom', 'email' => $this->target->email];
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertOk();
    $payload['email'] = 'attaquant@example.test';
    $this->putJson('/api/users/'.$this->target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($this->target->fresh()->email)->toBe($this->target->email)
        ->and($this->target->roles()->where('roleable_type', 'school')->count())->toBe(2);
});


it('annonce la modification uniquement pour les professeurs autorisés', function (bool $alsoAdmin) {
    identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'teacher'));
    if ($alsoAdmin) {
        identityGrant($this->target, $this->school, Role::staffFor($this->school->id, 'admin'));
    }
    $response = $this->getJson('/api/users/teachers')->assertOk();
    $teacher = collect($response->json('data'))->firstWhere('id', $this->target->id);
    expect($teacher)->not->toBeNull();
    expect($teacher['can_update'])->toBe(!$alsoAdmin);
    $update = $this->putJson('/api/users/'.$this->target->id, ['first_name' => 'Nouveau', 'last_name' => 'Nom']);
    $alsoAdmin ? $update->assertForbidden() : $update->assertOk();
})->with([false, true]);

it('ne publie plus les anciennes routes de consultation des utilisateurs', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri());
    expect($routes->contains('api/users/by-context'))->toBeFalse()
        ->and($routes->contains('api/users/classroom/{classroom}'))->toBeFalse();
});
