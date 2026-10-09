<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SchoolRoleProvisioner;
use App\Support\PermissionCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

function provisionedPermissionKeys(Role $role): array
{
    return $role->permissions()->pluck('key')->sort()->values()->all();
}

it('donne à une nouvelle école ses propres rôles staff avec les droits actuels', function () {
    $school = School::factory()->create();

    $roles = Role::forSchool($school->id)->get()->keyBy('slug');

    expect($roles->keys()->sort()->values()->all())->toBe(collect(PermissionCatalog::STAFF_SLUGS)->sort()->values()->all())
        ->and($roles['director']->is_locked)->toBeTrue()
        ->and($roles['admin']->is_locked)->toBeFalse()
        ->and(Permission::count())->toBe(count(PermissionCatalog::PERMISSIONS));

    foreach (PermissionCatalog::STAFF_SLUGS as $slug) {
        expect(provisionedPermissionKeys($roles[$slug]))
            ->toBe(collect(PermissionCatalog::defaultsFor($slug))->sort()->values()->all());
    }
});

it('donne des rôles distincts à chaque école', function () {
    $a = School::factory()->create();
    $b = School::factory()->create();

    expect(Role::staffFor($a->id, 'admin')->id)->not->toBe(Role::staffFor($b->id, 'admin')->id);
});

it('ne copie pas les rôles famille', function () {
    $school = School::factory()->create();

    expect(Role::forSchool($school->id)->whereIn('slug', ['student', 'responsible'])->exists())->toBeFalse();
});

it('raccroche les rattachements existants sur les rôles de l\'école', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();
    $template = Role::global()->where('slug', 'registar')->first();

    DB::table('user_roles')->insert([
        'user_id' => $user->id,
        'role_id' => $template->id,
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(SchoolRoleProvisioner::class)->provision($school->id);

    expect(DB::table('user_roles')->where('user_id', $user->id)->value('role_id'))
        ->toBe(Role::staffFor($school->id, 'registar')->id);
});

it('est idempotent et ne remet pas à zéro les droits modifiés par l\'école', function () {
    $school = School::factory()->create();
    $admin = Role::staffFor($school->id, 'admin');
    $admin->permissions()->sync([]);

    app(SchoolRoleProvisioner::class)->provision($school->id);

    expect(Role::forSchool($school->id)->count())->toBe(count(PermissionCatalog::STAFF_SLUGS))
        ->and($admin->permissions()->count())->toBe(0);
});

it('attribue le rôle de l\'école lors d\'un ajout de rôle', function () {
    $school = School::factory()->create(['access' => true]);
    $director = User::factory()->create(['access' => true]);
    $member = User::factory()->create(['access' => true]);

    foreach ([[$director, 'director'], [$member, 'teacher']] as [$user, $slug]) {
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::staffFor($school->id, $slug)->id,
            'roleable_type' => 'school',
            'roleable_id' => $school->id,
            'accepted_at' => now(),
        ]);
    }

    $this->actingAs($director, 'sanctum')
        ->withHeaders(['X-School-Id' => (string) $school->id])
        ->postJson('/api/users/add-role', ['user_id' => $member->id, 'school_id' => $school->id, 'role' => 'registar'])
        ->assertCreated();

    expect(UserRole::where('user_id', $member->id)->pluck('role_id'))
        ->toContain(Role::staffFor($school->id, 'registar')->id)
        ->not->toContain(Role::global()->where('slug', 'registar')->value('id'));
});
