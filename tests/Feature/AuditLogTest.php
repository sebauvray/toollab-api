<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    config(['toollab.super_admin_emails' => ['root@audit-test.com']]);

    $this->school = School::factory()->create(['access' => true]);
    $this->director = User::factory()->create(['access' => true]);
    $this->member = User::factory()->create(['access' => true]);
    $this->admin = User::factory()->create(['email' => 'root@audit-test.com', 'access' => true]);

    foreach ([[$this->director, 'director'], [$this->member, 'teacher']] as [$user, $slug]) {
        UserRole::create([
            'user_id' => $user->id, 'role_id' => Role::where('slug', $slug)->value('id'),
            'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
        ]);
    }
});

function auditStaffCall(string $uri, array $payload)
{
    return test()->actingAs(test()->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) test()->school->id])
        ->postJson($uri, $payload);
}

it('trace ajout, retrait de rôle et retrait de l\'école avec acteur et sujet', function () {
    $base = ['user_id' => $this->member->id, 'school_id' => $this->school->id];

    auditStaffCall('/api/users/add-role', $base + ['role' => 'registar'])->assertCreated();
    auditStaffCall('/api/users/remove-role', $base + ['role_name' => 'registar'])->assertOk();
    auditStaffCall('/api/users/remove-from-school', $base)->assertOk();

    $logs = AuditLog::orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['staff.role_added', 'staff.role_removed', 'staff.removed_from_school'])
        ->and($logs->every(fn ($l) => $l->actor_id === $this->director->id
            && $l->subject_id === $this->member->id
            && $l->school_id === $this->school->id))->toBeTrue()
        ->and($logs[0]->meta)->toBe(['roles' => ['registar']])
        ->and($logs[2]->meta)->toBe(['roles' => ['teacher']]);
});

it("ne trace rien quand l'action est refusée", function () {
    $this->actingAs($this->member, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->postJson('/api/users/remove-from-school', ['user_id' => $this->director->id, 'school_id' => $this->school->id])
        ->assertForbidden();

    expect(AuditLog::count())->toBe(0);
});

it("n'échoue jamais l'action métier si l'écriture d'audit plante", function () {
    AuditLog::creating(fn () => throw new RuntimeException('base indisponible'));

    auditStaffCall('/api/users/add-role', ['user_id' => $this->member->id, 'school_id' => $this->school->id, 'role' => 'registar'])
        ->assertCreated();
});

it('expose le journal filtrable au super-admin uniquement', function () {
    Audit::log('staff.role_added', $this->school->id, $this->member, ['roles' => ['admin']], $this->director);
    Audit::log('school.updated', $this->school->id, $this->school, ['fields' => ['name']], $this->director);

    $get = fn (array $p) => $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/audit-logs?'.http_build_query($p));

    $get([])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.action_label', 'École modifiée');
    $get(['action' => 'staff'])->assertJsonPath('total', 1);
    $get(['action' => 'staff,school'])->assertJsonPath('total', 2);
    $get(['user_id' => $this->member->id])->assertJsonPath('total', 1)->assertJsonPath('data.0.school', $this->school->name);

    $this->actingAs($this->director, 'sanctum')->getJson('/api/admin/audit-logs')->assertForbidden();
});
