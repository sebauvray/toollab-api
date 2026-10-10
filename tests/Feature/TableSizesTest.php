<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['toollab.super_admin_emails' => ['root@size-test.com']]);
    $this->admin = User::factory()->create(['email' => 'root@size-test.com', 'access' => true]);
});

it('prend une seule photo par jour, à la première consultation', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/dashboard')->assertOk()
        ->assertJsonStructure(['system' => ['database_size' => ['total_bytes', 'size_30d', 'compared_to']]]);
    $count = DB::table('table_size_snapshots')->count();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/database')->assertOk();

    expect($count)->toBeGreaterThan(10)
        ->and(DB::table('table_size_snapshots')->count())->toBe($count)
        ->and(DB::table('table_size_snapshots')->distinct()->pluck('snapshot_date')->all())->toBe([Carbon::today()->toDateString()]);
});

it('compte exactement les lignes et calcule la croissance depuis une photo passée', function () {
    $old = Carbon::today()->subDays(10)->toDateString();
    DB::table('table_size_snapshots')->insert([
        'snapshot_date' => $old, 'table_name' => 'users', 'rows' => 0, 'data_bytes' => 0, 'index_bytes' => 0,
    ]);
    User::factory()->count(3)->create();

    $users = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/database')->assertOk()->json('tables'))
        ->firstWhere('name', 'users');

    // 1 admin + 3 : COUNT(*) exact, pas l'estimation d'InnoDB
    expect($users['rows'])->toBe(4)
        ->and($users['rows_exact'])->toBeTrue()
        ->and($users['rows_7d'])->toBe(4)
        ->and($users['rows_30d'])->toBe(4);
});

it('réserve la page au super-admin', function () {
    $this->actingAs(User::factory()->create(['access' => true]), 'sanctum')->getJson('/api/admin/database')->assertForbidden();
});
