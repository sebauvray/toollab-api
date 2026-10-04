<?php

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->school = School::factory()->create(['access' => true]);
    $this->director = User::factory()->create(['access' => true]);
    $this->member = User::factory()->create(['access' => true]);

    foreach ([[$this->director, 'director'], [$this->member, 'teacher']] as [$user, $slug]) {
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $slug)->value('id'),
            'roleable_type' => 'school',
            'roleable_id' => $this->school->id,
            'accepted_at' => now(),
        ]);
    }
});

function staffCall(string $uri, array $payload)
{
    return test()->actingAs(test()->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) test()->school->id])
        ->postJson($uri, $payload);
}

it('ré-attribue un rôle retiré puis rajouté', function () {
    $base = ['user_id' => $this->member->id, 'school_id' => $this->school->id];

    staffCall('/api/users/add-role', $base + ['role' => 'registar'])->assertCreated();
    staffCall('/api/users/remove-role', $base + ['role_name' => 'registar'])->assertOk();
    staffCall('/api/users/add-role', $base + ['role' => 'registar'])->assertCreated();
    staffCall('/api/users/remove-role', $base + ['role_name' => 'registar'])->assertOk();
    staffCall('/api/users/create-staff', [
        'email' => $this->member->email, 'role' => 'registar', 'roles' => ['registar'], 'school_id' => $this->school->id,
    ])->assertCreated();

    expect(UserRole::withTrashed()->where('user_id', $this->member->id)->count())->toBe(2);
});

it('absorbe un reliquat soft-deleted hérité lors de la ré-attribution', function () {
    UserRole::create([
        'user_id' => $this->member->id,
        'role_id' => Role::where('slug', 'admin')->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now(),
    ])->delete();

    staffCall('/api/users/add-role', [
        'user_id' => $this->member->id, 'school_id' => $this->school->id, 'role' => 'admin',
    ])->assertCreated();

    expect(UserRole::onlyTrashed()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('retire définitivement les rôles lors d\'un retrait de l\'établissement', function () {
    staffCall('/api/users/remove-from-school', [
        'user_id' => $this->member->id, 'school_id' => $this->school->id,
    ])->assertOk();

    expect(UserRole::withTrashed()->where('user_id', $this->member->id)->exists())->toBeFalse();

    staffCall('/api/users/create-staff', [
        'email' => $this->member->email, 'role' => 'teacher', 'roles' => ['teacher'], 'school_id' => $this->school->id,
    ])->assertCreated();
});
