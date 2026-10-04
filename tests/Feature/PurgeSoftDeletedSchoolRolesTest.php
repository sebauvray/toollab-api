<?php

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->school = School::factory()->create(['access' => true]);

    $active = new SchoolYear(['label' => '2026-2027', 'opened_at' => now(), 'is_active' => true]);
    $active->school_id = $this->school->id;
    $active->save();

    $this->archived = new SchoolYear(['label' => '2025-2026', 'opened_at' => now()->subYear(), 'is_active' => false, 'closed_at' => now()->subDays(10)]);
    $this->archived->school_id = $this->school->id;
    $this->archived->save();

    $this->user = User::factory()->create(['access' => true]);

    $grant = fn (string $slug) => UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => Role::where('slug', $slug)->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $this->school->id,
        'accepted_at' => now()->subMonth(),
    ]);

    $grant('registar');
    $grant('admin');
    DB::table('user_roles')
        ->where('user_id', $this->user->id)
        ->where('role_id', Role::where('slug', 'admin')->value('id'))
        ->update(['deleted_at' => now()->subDay()]);
});

function archivedYearPilotage()
{
    return test()->actingAs(test()->user, 'sanctum')
        ->withHeaders([
            'X-School-Id' => (string) test()->school->id,
            'X-School-Year-Id' => (string) test()->archived->id,
        ])
        ->getJson('/api/cursus');
}

it('un rôle école retiré après la clôture ne donne plus accès à l\'année archivée une fois purgé', function () {
    archivedYearPilotage()->assertOk();

    (require database_path('migrations/2026_10_04_100000_purge_soft_deleted_school_roles.php'))->up();
    app('auth')->forgetGuards();

    archivedYearPilotage()->assertForbidden();
    expect(DB::table('user_roles')->where('user_id', $this->user->id)->pluck('deleted_at')->filter()->all())->toBe([]);
});

it('ne touche pas aux liens famille et classe de la corbeille', function () {
    DB::table('user_roles')->insert([
        ['user_id' => $this->user->id, 'role_id' => Role::where('slug', 'responsible')->value('id'), 'roleable_type' => 'family', 'roleable_id' => 999, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $this->user->id, 'role_id' => Role::where('slug', 'student')->value('id'), 'roleable_type' => 'classroom', 'roleable_id' => 999, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    (require database_path('migrations/2026_10_04_100000_purge_soft_deleted_school_roles.php'))->up();

    expect(DB::table('user_roles')->whereNotNull('deleted_at')->pluck('roleable_type')->sort()->values()->all())
        ->toBe(['classroom', 'family'])
        ->and(DB::table('user_roles')->where('user_id', $this->user->id)->where('roleable_type', 'school')->count())->toBe(1);
});
