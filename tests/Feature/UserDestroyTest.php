<?php

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function grantSchoolRole(User $user, School $school, string $slug): void
{
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => Role::where('slug', $slug)->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create(['access' => true]);
    $this->other = School::factory()->create(['access' => true]);
    (new SchoolYear)->forceFill([
        'school_id' => $this->school->id, 'label' => '2026-2027', 'is_active' => true, 'opened_at' => now()->subMonth(),
    ])->save();
    $this->director = User::factory()->create(['access' => true]);
    $this->member = User::factory()->create(['access' => true]);

    grantSchoolRole($this->director, $this->school, 'director');
    grantSchoolRole($this->member, $this->school, 'teacher');
});

function destroyCall(User $target)
{
    return test()->actingAs(test()->director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) test()->school->id])
        ->deleteJson("/api/users/{$target->id}");
}

it("retire le compte de l'école courante sans le supprimer", function () {
    destroyCall($this->member)->assertNoContent();

    expect(User::find($this->member->id))->not->toBeNull()
        ->and(UserRole::withTrashed()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('conserve les rôles du compte dans les autres écoles', function () {
    grantSchoolRole($this->member, $this->other, 'teacher');

    destroyCall($this->member)->assertNoContent();

    expect(UserRole::where('user_id', $this->member->id)->pluck('roleable_id')->all())
        ->toBe([$this->other->id]);
});

it("refuse un compte qui n'appartient pas à l'école courante", function () {
    $outsider = User::factory()->create(['access' => true]);
    grantSchoolRole($outsider, $this->other, 'teacher');

    destroyCall($outsider)->assertStatus(403);
});

it('refuse toujours de retirer un directeur en poste', function () {
    $coDirector = User::factory()->create(['access' => true]);
    grantSchoolRole($coDirector, $this->school, 'director');

    destroyCall($coDirector)->assertStatus(422);

    expect(UserRole::where('user_id', $coDirector->id)->exists())->toBeTrue();
});
