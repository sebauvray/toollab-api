<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    config(['toollab.super_admin_emails' => ['root@flag-test.com']]);

    $this->school = School::factory()->create(['access' => true]);
    $this->other = School::factory()->create(['access' => true]);
    foreach ([$this->school, $this->other] as $school) {
        $year = new SchoolYear(['label' => '2025-2026', 'opened_at' => now(), 'is_active' => true]);
        $year->school_id = $school->id;
        $year->save();
    }

    $this->director = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $this->director->id, 'role_id' => Role::staffFor($this->school->id, 'director')->id,
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
    $this->admin = User::factory()->create(['email' => 'root@flag-test.com', 'access' => true]);
});

function flagCreateRole($test)
{
    return $test->actingAs($test->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $test->school->id])
        ->postJson('/api/roles', ['name' => 'Trésorier', 'permissions' => ['families.view']]);
}

function flagToggle($test, School $school, bool $enabled)
{
    return $test->actingAs($test->admin, 'sanctum')
        ->putJson("/api/admin/schools/{$school->id}/features/custom_roles", ['enabled' => $enabled]);
}

it('applique la valeur par défaut du catalogue sans surcharge', function () {
    flagCreateRole($this)->assertCreated();

    $this->actingAs($this->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->getJson('/api/features')
        ->assertOk()
        ->assertExactJson(['custom_roles' => true]);
});

it("bloque la route quand l'école a la fonctionnalité désactivée, sans toucher les autres écoles", function () {
    flagToggle($this, $this->school, false)->assertOk()->assertJsonPath('0.enabled', false);

    flagCreateRole($this)->assertForbidden()->assertJsonPath('feature_disabled', 'custom_roles');
    expect(Role::where('name', 'Trésorier')->exists())->toBeFalse();

    // La lecture des rôles reste possible
    $this->actingAs($this->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->getJson('/api/roles')->assertOk();

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/schools/{$this->other->id}/features")
        ->assertJsonPath('0.enabled', true);
});

it('trace chaque bascule dans le journal, mais pas une valeur inchangée', function () {
    flagToggle($this, $this->school, false);
    flagToggle($this, $this->school, false);
    flagToggle($this, $this->school, true);

    expect(AuditLog::where('action', 'feature.toggled')->pluck('meta')->pluck('enabled')->all())->toBe([false, true]);
    flagCreateRole($this)->assertCreated();
});

it('réserve la gestion des flags au super-admin et refuse une clé inconnue', function () {
    $this->actingAs($this->director, 'sanctum')
        ->putJson("/api/admin/schools/{$this->school->id}/features/custom_roles", ['enabled' => false])
        ->assertForbidden();

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/schools/{$this->school->id}/features/inconnue", ['enabled' => true])
        ->assertUnprocessable();
});
