<?php

use App\Models\User;
use App\Support\ErrorRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['toollab.super_admin_emails' => ['root@error-test.com'], 'app.debug' => false]);
    $this->admin = User::factory()->create(['email' => 'root@error-test.com', 'access' => true]);

    Route::middleware('api')->prefix('api')->group(function () {
        Route::get('/_test/boom/{id}', fn () => throw new RuntimeException('Échec du calcul'));
        Route::get('/_test/invalid', fn () => request()->validate(['x' => 'required']));
        Route::get('/_test/forbidden', fn () => abort(403));
    });
});

it("enregistre une erreur 500 sans changer la réponse, et regroupe les occurrences", function () {
    $this->getJson('/api/_test/boom/1')->assertStatus(500)->assertJsonPath('message', 'Une erreur est survenue');
    $this->getJson('/api/_test/boom/2')->assertStatus(500);

    $group = DB::table('error_groups')->sole();
    expect($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->message)->toBe('Échec du calcul')
        ->and($group->occurrences)->toBe(2)
        ->and($group->category)->toBe('http')
        // Motif de route, sans les identifiants
        ->and($group->context)->toBe('GET /api/_test/boom/{id}')
        ->and($group->file)->toStartWith('tests/')
        ->and(DB::table('error_events')->count())->toBe(2);
});

it('ignore les erreurs attendues (validation, 4xx, 404)', function () {
    $this->getJson('/api/_test/invalid')->assertUnprocessable();
    $this->getJson('/api/_test/forbidden')->assertForbidden();
    $this->getJson('/api/route-inexistante')->assertNotFound();

    expect(DB::table('error_groups')->count())->toBe(0);
});

it('rouvre une erreur résolue qui réapparaît', function () {
    $this->getJson('/api/_test/boom/1');
    $id = DB::table('error_groups')->value('id');

    $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/errors/{$id}/resolve")->assertOk();
    expect(DB::table('error_groups')->value('resolved_at'))->not->toBeNull();

    app('auth')->forgetGuards();
    $this->getJson('/api/_test/boom/1');
    expect(DB::table('error_groups')->value('resolved_at'))->toBeNull();
});

it("classe en « mail » une erreur survenue pendant l'envoi d'une notification", function () {
    ErrorRecorder::$currentJob = 'App\\Notifications\\StaffInvitation';
    ErrorRecorder::$currentJobIsMail = true;
    try {
        ErrorRecorder::record(new RuntimeException('SMTP injoignable'));
    } finally {
        ErrorRecorder::$currentJob = null;
        ErrorRecorder::$currentJobIsMail = false;
    }

    $group = DB::table('error_groups')->sole();
    expect($group->category)->toBe('mail')->and($group->context)->toBe('Job App\\Notifications\\StaffInvitation');

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/dashboard')
        ->assertJsonPath('system.errors.mail_failures_7d', 1)
        ->assertJsonPath('system.errors.open', 1)
        ->assertJsonPath('system.errors.hourly.23', 1);
});

it('expose liste et détail au super-admin uniquement', function () {
    $this->getJson('/api/_test/boom/1');
    $id = DB::table('error_groups')->value('id');

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/errors')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.last_24h', 1);
    $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/errors/{$id}")
        ->assertOk()->assertJsonPath('hourly.23', 1)->assertJsonStructure(['last_trace']);

    $user = User::factory()->create(['access' => true]);
    app('auth')->forgetGuards();
    $this->actingAs($user, 'sanctum')->getJson('/api/admin/errors')->assertForbidden();
});
