<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\DirectorMessageNotification;
use App\Notifications\SchoolStatusNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    config(['toollab.super_admin_emails' => ['root@suspend-test.com']]);

    $this->school = School::factory()->create(['access' => true, 'name' => 'École Test']);
    $year = new SchoolYear(['label' => '2025-2026', 'opened_at' => now(), 'is_active' => true]);
    $year->school_id = $this->school->id;
    $year->save();

    $this->director = User::factory()->create(['access' => true]);
    UserRole::create([
        'user_id' => $this->director->id, 'role_id' => Role::where('slug', 'director')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $this->school->id, 'accepted_at' => now(),
    ]);
    $this->admin = User::factory()->create(['email' => 'root@suspend-test.com', 'access' => true]);
});

function asDirectorInSchool($test)
{
    app('auth')->forgetGuards();

    return $test->actingAs($test->director, 'sanctum')->withHeaders(['X-School-Id' => (string) $test->school->id]);
}

it("suspend l'école : son équipe est bloquée, le super-admin non, le directeur est prévenu", function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/schools/{$this->school->id}/suspend", ['reason' => 'Impayé'])
        ->assertOk()
        ->assertJsonPath('access', false)
        ->assertJsonPath('director_notified', true);

    asDirectorInSchool($this)->getJson('/api/families')
        ->assertForbidden()
        ->assertJsonPath('school_suspended', true);

    app('auth')->forgetGuards();
    $this->actingAs($this->admin, 'sanctum')->withHeaders(['X-School-Id' => (string) $this->school->id])
        ->getJson('/api/families')->assertOk();

    Notification::assertSentTo($this->director, SchoolStatusNotification::class);
    $log = AuditLog::where('action', 'school.suspended')->sole();
    expect($log->meta)->toBe(['reason' => 'Impayé', 'director_notified' => true]);
});

it('réactive : accès rétabli, motif effacé, sans notification si demandé', function () {
    $this->school->forceFill(['access' => false, 'suspended_at' => now(), 'suspension_reason' => 'x'])->save();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/schools/{$this->school->id}/reactivate", ['notify_director' => false])
        ->assertOk()
        ->assertJsonPath('access', true)
        ->assertJsonPath('suspension_reason', null);

    asDirectorInSchool($this)->getJson('/api/families')->assertOk();
    Notification::assertNothingSent();
});

it('refuse une double suspension et une suspension sans motif', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/schools/{$this->school->id}/suspend", [])->assertUnprocessable();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/schools/{$this->school->id}/suspend", ['reason' => 'Impayé'])->assertOk();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/schools/{$this->school->id}/suspend", ['reason' => 'Impayé'])->assertStatus(409);
});

it("empêche un directeur de modifier lui-même l'accès de son école", function () {
    $this->school->forceFill(['access' => false])->save();
    $this->school->forceFill(['access' => true])->save();

    asDirectorInSchool($this)->putJson("/api/schools/{$this->school->id}", ['name' => 'Nouveau nom', 'address' => '1 rue', 'access' => false])
        ->assertOk();

    expect($this->school->fresh())->name->toBe('Nouveau nom')->access->toBeTruthy();
});

it('envoie un message au directeur avec réponse au super-admin, et le trace', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/schools/{$this->school->id}/contact-director", ['subject' => 'Point mensuel', 'message' => "Bonjour,\nOn fait le point ?"])
        ->assertOk()
        ->assertJsonPath('sent_to', $this->director->email);

    Notification::assertSentTo($this->director, DirectorMessageNotification::class, function ($n) {
        $mail = $n->toMail($this->director);

        return $mail->subject === 'Point mensuel' && $mail->replyTo[0][0] === 'root@suspend-test.com';
    });
    expect(AuditLog::where('action', 'school.director_contacted')->value('subject_id'))->toBe($this->director->id);
});

it("refuse le contact sans directeur et réserve ces actions au super-admin", function () {
    UserRole::where('user_id', $this->director->id)->forceDelete();
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/schools/{$this->school->id}/contact-director", ['subject' => 'Bonjour', 'message' => 'Test'])
        ->assertUnprocessable();

    $this->actingAs($this->director, 'sanctum')
        ->postJson("/api/admin/schools/{$this->school->id}/suspend", ['reason' => 'test'])
        ->assertForbidden();
});

it('rend les deux e-mails sans erreur', function () {
    $html = (new SchoolStatusNotification('École <b>Test</b>', 'suspended', 'Impayé', null))->toMail($this->director)->render();
    expect((string) $html)->toContain('Établissement suspendu')->toContain('Impayé')->not->toContain('<b>Test</b>');

    $html = (new DirectorMessageNotification('École Test', 'Sujet', "Ligne 1\n<script>x</script>", 'Root', 'root@suspend-test.com'))->toMail($this->director)->render();
    expect((string) $html)->toContain('Ligne 1<br />')->not->toContain('<script>x</script>');
});
