<?php

use App\Models\Family;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\DirectorHandoverInvitation;
use App\Notifications\DirectorHandoverStatusNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function dhUser(string $email, ?string $first = 'Prénom', ?string $last = 'Nom'): User
{
    return User::factory()->create([
        'email' => $email,
        'first_name' => $first,
        'last_name' => $last,
        'access' => true,
    ]);
}

function dhGrant(User $user, string $slug, School $school, bool $accepted = true): UserRole
{
    return UserRole::create([
        'user_id' => $user->id,
        'role_id' => Role::where('slug', $slug)->value('id'),
        'roleable_type' => 'school',
        'roleable_id' => $school->id,
        'accepted_at' => $accepted ? now() : null,
    ]);
}

function dhRoles(User $user, School $school): array
{
    return DB::table('user_roles')
        ->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('user_roles.user_id', $user->id)
        ->where('user_roles.roleable_type', 'school')
        ->where('user_roles.roleable_id', $school->id)
        ->whereNull('user_roles.deleted_at')
        ->orderBy('roles.slug')
        ->pluck('roles.slug')
        ->all();
}

function dhAs(User $user, School $school)
{
    return test()->actingAs($user, 'sanctum')->withHeaders(['X-School-Id' => (string) $school->id]);
}

function dhLastToken(string $email): string
{
    $token = null;
    Notification::assertSentOnDemand(DirectorHandoverInvitation::class, function ($notification, $channels, $notifiable) use ($email, &$token) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $token = $notification->token;
        }

        return true;
    });

    return $token;
}

function dhInitiate(User $director, School $school, string $email, string $outgoing = 'admin', bool $removeTeacher = false): string
{
    dhAs($director, $school)
        ->postJson('/api/director-handover', ['email' => $email, 'outgoing_role' => $outgoing, 'remove_teacher_role' => $removeTeacher])
        ->assertCreated();

    return dhLastToken($email);
}

function dhAcceptAsNewUser(string $token)
{
    test()->postJson('/api/director-handover/accept', [
        'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
        'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
    ])->assertOk();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->school = School::factory()->create(['name' => 'École Test', 'access' => true]);
    $this->otherSchool = School::factory()->create(['name' => 'Autre École', 'access' => true]);

    $year = new SchoolYear(['label' => '2026-2027', 'opened_at' => now(), 'is_active' => true]);
    $year->school_id = $this->school->id;
    $year->save();

    $this->director = dhUser('ancien.directeur@test.fr', 'Ancien', 'Directeur');
    dhGrant($this->director, 'director', $this->school);
});

describe('initiation', function () {
    it('permet au directeur de lancer une passation et envoie l\'invitation', function () {
        $response = dhAs($this->director, $this->school)
            ->postJson('/api/director-handover', ['email' => 'Nouveau@Test.fr', 'outgoing_role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.email', 'nouveau@test.fr')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.token_hash');

        $token = dhLastToken('nouveau@test.fr');
        expect($token)->toHaveLength(64);

        $row = DB::table('director_handovers')->first();
        expect($row->token_hash)->toBe(hash('sha256', $token))
            ->and($row->token_hash)->not->toBe($token)
            ->and($row->from_user_id)->toBe($this->director->id)
            ->and($row->school_id)->toBe($this->school->id)
            ->and($row->created_by)->toBe($this->director->id);

        dhAs($this->director, $this->school)
            ->getJson('/api/director-handover')
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'))
            ->assertJsonPath('data.outgoing_role', 'admin')
            ->assertJsonPath('data.remove_teacher_role', false);

        expect(dhRoles($this->director, $this->school))->toBe(['director']);
    });

    it('renvoie null quand aucune passation n\'est en cours', function () {
        dhAs($this->director, $this->school)
            ->getJson('/api/director-handover')
            ->assertOk()
            ->assertJsonPath('data', null);
    });

    it('refuse les rôles non directeur', function (string $slug) {
        $member = dhUser("{$slug}@test.fr");
        dhGrant($member, $slug, $this->school);

        dhAs($member, $this->school)->getJson('/api/director-handover')->assertForbidden();
        dhAs($member, $this->school)
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertForbidden();

        expect(DB::table('director_handovers')->count())->toBe(0);
        Notification::assertNothingSent();
    })->with(['admin', 'registar', 'teacher']);

    it('refuse un super-admin qui n\'est pas directeur de l\'école', function () {
        config(['toollab.super_admin_emails' => ['super@test.fr']]);
        $super = dhUser('super@test.fr');

        dhAs($super, $this->school)
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertForbidden();
        dhAs($super, $this->school)->getJson('/api/director-handover')->assertForbidden();
    });

    it('refuse un directeur dont l\'adhésion n\'est pas acceptée', function () {
        $pending = dhUser('pending@test.fr');
        dhGrant($pending, 'director', $this->otherSchool, accepted: false);

        dhAs($pending, $this->otherSchool)
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertForbidden();
    });

    it('refuse d\'agir sur une école où l\'appelant n\'est pas directeur', function () {
        dhGrant($this->director, 'admin', $this->otherSchool);

        dhAs($this->director, $this->otherSchool)
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertForbidden();

        $stranger = dhUser('stranger@test.fr');
        dhGrant($stranger, 'director', $this->otherSchool);
        dhAs($stranger, $this->school)
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertForbidden();
    });

    it('exige une authentification et un contexte école', function () {
        $this->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertUnauthorized();

        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertStatus(400);
    });

    it('valide l\'e-mail et le rôle de sortie', function (array $payload, string $field) {
        dhAs($this->director, $this->school)
            ->postJson('/api/director-handover', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'email manquant' => [['outgoing_role' => 'admin'], 'email'],
        'email invalide' => [['email' => 'pas-un-email', 'outgoing_role' => 'admin'], 'email'],
        'rôle manquant' => [['email' => 'x@test.fr'], 'outgoing_role'],
        'rôle director' => [['email' => 'x@test.fr', 'outgoing_role' => 'director'], 'outgoing_role'],
        'rôle teacher' => [['email' => 'x@test.fr', 'outgoing_role' => 'teacher'], 'outgoing_role'],
        'rôle tableau' => [['email' => 'x@test.fr', 'outgoing_role' => ['admin']], 'outgoing_role'],
        'retrait professeur invalide' => [['email' => 'x@test.fr', 'outgoing_role' => 'admin', 'remove_teacher_role' => 'peut-être'], 'remove_teacher_role'],
    ]);

    it('refuse de se transmettre la direction à soi-même, casse comprise', function () {
        dhAs($this->director, $this->school)
            ->postJson('/api/director-handover', ['email' => 'ANCIEN.directeur@test.fr', 'outgoing_role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    });

    it('refuse une personne déjà directrice de l\'établissement', function () {
        $co = dhUser('codirecteur@test.fr');
        dhGrant($co, 'director', $this->school);

        dhAs($this->director, $this->school)
            ->postJson('/api/director-handover', ['email' => 'codirecteur@test.fr', 'outgoing_role' => 'admin'])
            ->assertStatus(422);
    });

    it('refuse une seconde passation tant qu\'une autre est en cours', function () {
        dhInitiate($this->director, $this->school, 'premier@test.fr');

        dhAs($this->director, $this->school)
            ->postJson('/api/director-handover', ['email' => 'second@test.fr', 'outgoing_role' => 'admin'])
            ->assertStatus(409);

        expect(DB::table('director_handovers')->count())->toBe(1);
    });

    it('autorise une nouvelle passation quand la précédente a expiré', function () {
        dhInitiate($this->director, $this->school, 'premier@test.fr');
        DB::table('director_handovers')->update(['expires_at' => now()->subMinute()]);

        dhAs($this->director, $this->school)
            ->getJson('/api/director-handover')
            ->assertJsonPath('data.status', 'expired');

        dhInitiate($this->director, $this->school, 'second@test.fr');

        expect(DB::table('director_handovers')->pluck('status', 'email')->all())
            ->toBe(['premier@test.fr' => 'expired', 'second@test.fr' => 'pending']);
    });

    it('limite les envois par directeur et non par adresse IP', function () {
        $send = function (User $user, School $school, string $email) {
            app('auth')->forgetGuards();

            return test()->withHeaders([
                'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
                'X-School-Id' => (string) $school->id,
            ])->postJson('/api/director-handover', ['email' => $email, 'outgoing_role' => 'admin']);
        };

        foreach (range(1, 10) as $i) {
            $send($this->director, $this->school, "spam{$i}@test.fr");
        }
        $send($this->director, $this->school, 'spam11@test.fr')->assertStatus(429);

        $autre = dhUser('autre.directeur@test.fr');
        dhGrant($autre, 'director', $this->otherSchool);
        $send($autre, $this->otherSchool, 'cible@test.fr')->assertCreated();
    });

    it('n\'est pas bloquée sur une année scolaire archivée', function () {
        $year = new SchoolYear(['label' => '2020-2021', 'opened_at' => now()->subYears(5), 'is_active' => false]);
        $year->school_id = $this->school->id;
        $year->closed_at = now()->subYears(4);
        $year->save();

        dhAs($this->director, $this->school)
            ->withHeaders(['X-School-Year-Id' => (string) $year->id])
            ->postJson('/api/director-handover', ['email' => 'x@test.fr', 'outgoing_role' => 'admin'])
            ->assertCreated();
    });
});

describe('annulation et renvoi', function () {
    it('annule la passation et invalide le lien', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        $id = DB::table('director_handovers')->value('id');

        dhAs($this->director, $this->school)->postJson("/api/director-handover/{$id}/cancel")->assertOk();

        expect(DB::table('director_handovers')->value('status'))->toBe('cancelled');
        $this->postJson('/api/director-handover/check', ['token' => $token])->assertNotFound();
        $this->postJson('/api/director-handover/accept', ['token' => $token, 'password' => 'password123', 'password_confirmation' => 'password123', 'first_name' => 'A', 'last_name' => 'B'])
            ->assertNotFound();
        dhAs($this->director, $this->school)->postJson("/api/director-handover/{$id}/cancel")->assertNotFound();
        dhAs($this->director, $this->school)->getJson('/api/director-handover')->assertJsonPath('data', null);
    });

    it('ne permet pas d\'annuler ou renvoyer la passation d\'une autre école', function () {
        $otherDirector = dhUser('autre.directeur@test.fr');
        dhGrant($otherDirector, 'director', $this->otherSchool);
        dhInitiate($otherDirector, $this->otherSchool, 'cible@test.fr');
        $id = DB::table('director_handovers')->value('id');

        dhAs($this->director, $this->school)->postJson("/api/director-handover/{$id}/cancel")->assertNotFound();
        dhAs($this->director, $this->school)->postJson("/api/director-handover/{$id}/resend")->assertNotFound();

        expect(DB::table('director_handovers')->value('status'))->toBe('pending');
    });

    it('seul l\'initiateur peut renvoyer l\'invitation, un codirecteur peut l\'annuler', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        $co = dhUser('codirecteur@test.fr');
        dhGrant($co, 'director', $this->school);
        $id = DB::table('director_handovers')->value('id');

        dhAs($co, $this->school)->postJson("/api/director-handover/{$id}/resend")->assertForbidden();
        $this->postJson('/api/director-handover/check', ['token' => $token])->assertOk();

        dhAs($co, $this->school)->postJson("/api/director-handover/{$id}/cancel")->assertOk();
        expect(DB::table('director_handovers')->value('status'))->toBe('cancelled');
    });

    it('le renvoi régénère le lien et prolonge la validité', function () {
        $oldToken = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        DB::table('director_handovers')->update(['expires_at' => now()->subDay()]);
        $id = DB::table('director_handovers')->value('id');

        dhAs($this->director, $this->school)
            ->postJson("/api/director-handover/{$id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $newToken = dhLastToken('nouveau@test.fr');
        expect($newToken)->not->toBe($oldToken);

        $this->postJson('/api/director-handover/check', ['token' => $oldToken])->assertNotFound();
        $this->postJson('/api/director-handover/check', ['token' => $newToken])->assertOk();
    });
});

describe('consultation du lien', function () {
    it('décrit l\'invitation pour un nouvel utilisateur', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');

        $this->postJson('/api/director-handover/check', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.school_name', 'École Test')
            ->assertJsonPath('data.from_name', 'Ancien Directeur')
            ->assertJsonPath('data.has_account', false)
            ->assertJsonPath('data.requires_password', true)
            ->assertJsonPath('data.requires_profile', true);
    });

    it('décrit l\'invitation pour un compte existant', function () {
        dhUser('existant@test.fr');
        $token = dhInitiate($this->director, $this->school, 'existant@test.fr');

        $this->postJson('/api/director-handover/check', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('data.requires_password', false)
            ->assertJsonPath('data.requires_profile', false);
    });

    it('rejette un jeton inconnu, vide ou expiré', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');

        $this->postJson('/api/director-handover/check', ['token' => 'inconnu'])->assertNotFound();
        $this->postJson('/api/director-handover/check', [])->assertStatus(422);
        $this->postJson('/api/director-handover/check', ['token' => DB::table('director_handovers')->value('token_hash')])->assertNotFound();

        DB::table('director_handovers')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/director-handover/check', ['token' => $token])->assertNotFound();
    });
});

describe('acceptation', function () {
    it('crée le compte du nouveau directeur et rétrograde l\'ancien en administrateur', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr', 'admin');

        $this->postJson('/api/director-handover/accept', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'first_name', 'last_name']);

        $this->postJson('/api/director-handover/accept', [
            'token' => $token,
            'first_name' => 'Nouvelle',
            'last_name' => 'Directrice',
            'password' => 'motdepasse1',
            'password_confirmation' => 'autre',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        expect(User::where('email', 'nouveau@test.fr')->exists())->toBeFalse();

        $this->postJson('/api/director-handover/accept', [
            'token' => $token,
            'first_name' => 'Nouvelle',
            'last_name' => 'Directrice',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])->assertOk()->assertJsonPath('data.school_id', $this->school->id);

        $new = User::where('email', 'nouveau@test.fr')->firstOrFail();
        expect($new->first_name)->toBe('Nouvelle')
            ->and(Hash::check('motdepasse1', $new->password))->toBeTrue()
            ->and(dhRoles($new, $this->school))->toBe(['director'])
            ->and(dhRoles($this->director, $this->school))->toBe(['admin'])
            ->and(UserRole::where('user_id', $new->id)->whereNull('accepted_at')->exists())->toBeFalse();

        $row = DB::table('director_handovers')->first();
        expect($row->status)->toBe('accepted')
            ->and($row->to_user_id)->toBe($new->id)
            ->and($row->responded_at)->not->toBeNull();

        Notification::assertSentTo($this->director, DirectorHandoverStatusNotification::class,
            fn ($n) => $n->action === 'accepted');

        $this->postJson('/api/login', ['email' => 'nouveau@test.fr', 'password' => 'motdepasse1'])->assertSuccessful()->assertJsonStructure(['token']);

        dhAs($new, $this->school)->getJson('/api/director-handover')->assertOk();
        dhAs($this->director, $this->school)->getJson('/api/director-handover')->assertForbidden();
        dhAs($this->director, $this->school)->getJson('/api/families')->assertOk();
    });

    it('le lien est à usage unique', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        $payload = ['token' => $token, 'first_name' => 'N', 'last_name' => 'D', 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'];

        $this->postJson('/api/director-handover/accept', $payload)->assertOk();
        $this->postJson('/api/director-handover/accept', $payload)->assertNotFound();
        $this->postJson('/api/director-handover/decline', ['token' => $token])->assertNotFound();
    });

    it('accepte pour un compte existant sans toucher à son mot de passe ni à ses autres rôles', function () {
        $teacher = dhUser('prof@test.fr', 'Prof', 'Existant');
        dhGrant($teacher, 'teacher', $this->school);
        $hash = $teacher->password;
        $token = dhInitiate($this->director, $this->school, 'prof@test.fr', 'registar');

        $this->postJson('/api/director-handover/accept', ['token' => $token, 'password' => 'ignored1', 'password_confirmation' => 'ignored1'])
            ->assertOk();

        expect($teacher->fresh()->password)->toBe($hash)
            ->and(dhRoles($teacher, $this->school))->toBe(['director', 'teacher'])
            ->and(dhRoles($this->director, $this->school))->toBe(['registar']);
    });

    it('retire les rôles admin et registar devenus redondants chez le nouveau directeur', function () {
        $admin = dhUser('admin@test.fr');
        dhGrant($admin, 'admin', $this->school);
        dhGrant($admin, 'registar', $this->school);
        $token = dhInitiate($this->director, $this->school, 'admin@test.fr');

        $this->postJson('/api/director-handover/accept', ['token' => $token])->assertOk();

        expect(dhRoles($admin, $this->school))->toBe(['director']);
    });

    it('retire complètement l\'ancien directeur de l\'établissement sans toucher à ses rôles famille ni aux autres écoles', function () {
        dhGrant($this->director, 'teacher', $this->school);
        dhGrant($this->director, 'admin', $this->otherSchool);
        $family = Family::query()->withoutGlobalScopes()->forceCreate(['school_id' => $this->otherSchool->id]);
        UserRole::create([
            'user_id' => $this->director->id,
            'role_id' => Role::where('slug', 'responsible')->value('id'),
            'roleable_type' => 'family',
            'roleable_id' => $family->id,
        ]);

        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr', 'none', true);
        $this->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertOk();

        expect(dhRoles($this->director, $this->school))->toBe([])
            ->and(UserRole::withTrashed()->where('user_id', $this->director->id)->where('roleable_type', 'school')->where('roleable_id', $this->school->id)->exists())->toBeFalse()
            ->and(dhRoles($this->director, $this->otherSchool))->toBe(['admin'])
            ->and(UserRole::where('user_id', $this->director->id)->where('roleable_type', 'family')->exists())->toBeTrue();

        dhAs($this->director, $this->school)->getJson('/api/director-handover')->assertForbidden();
        dhAs($this->director, $this->school)->getJson('/api/families')->assertForbidden();

        Notification::assertSentTo($this->director, DirectorHandoverStatusNotification::class);
    });

    it('l\'ancien directeur professeur garde son rôle professeur par défaut', function (string $outgoing, array $expected) {
        dhGrant($this->director, 'teacher', $this->school);

        dhAcceptAsNewUser(dhInitiate($this->director, $this->school, 'nouveau@test.fr', $outgoing));

        expect(dhRoles($this->director, $this->school))->toBe($expected);
        Notification::assertSentTo($this->director, DirectorHandoverStatusNotification::class,
            fn ($n) => $n->toArray($this->director)['remaining_roles'] === collect($expected)->map(fn ($slug) => Role::where('slug', $slug)->value('name'))->sort()->values()->all());
    })->with([
        'devient admin' => ['admin', ['admin', 'teacher']],
        'devient registar' => ['registar', ['registar', 'teacher']],
        'quitte la direction' => ['none', ['teacher']],
    ]);

    it('retire le rôle professeur quand l\'ancien directeur l\'a demandé', function (string $outgoing, array $expected) {
        dhGrant($this->director, 'teacher', $this->school);

        dhAcceptAsNewUser(dhInitiate($this->director, $this->school, 'nouveau@test.fr', $outgoing, true));

        expect(dhRoles($this->director, $this->school))->toBe($expected);
    })->with([
        'devient admin' => ['admin', ['admin']],
        'quitte l\'établissement' => ['none', []],
    ]);

    it('ignore la demande de retrait du rôle professeur pour un directeur qui ne l\'a pas', function () {
        dhAcceptAsNewUser(dhInitiate($this->director, $this->school, 'nouveau@test.fr', 'registar', true));

        expect(dhRoles($this->director, $this->school))->toBe(['registar']);
    });

    it('réattribue un rôle précédemment supprimé (soft delete) sans violer la contrainte unique', function () {
        dhGrant($this->director, 'admin', $this->school)->delete();
        expect(UserRole::onlyTrashed()->where('user_id', $this->director->id)->count())->toBe(1);

        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr', 'admin');
        $this->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertOk();

        expect(dhRoles($this->director, $this->school))->toBe(['admin']);
    });

    it('active un compte invité jamais activé et révoque ses jetons', function () {
        $invited = dhUser('invite@test.fr', null, null);
        dhGrant($invited, 'teacher', $this->school, accepted: false);
        InvitationToken::create(['email' => 'invite@test.fr', 'token' => 'tok', 'school_id' => $this->school->id, 'expires_at' => now()->addDay()]);
        $invited->createToken('old');

        $token = dhInitiate($this->director, $this->school, 'invite@test.fr');

        $this->postJson('/api/director-handover/check', ['token' => $token])
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('data.requires_password', true)
            ->assertJsonPath('data.requires_profile', true);

        $this->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'Invité', 'last_name' => 'Activé',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertOk();

        $invited->refresh();
        expect($invited->first_name)->toBe('Invité')
            ->and(Hash::check('motdepasse1', $invited->password))->toBeTrue()
            ->and($invited->tokens()->count())->toBe(0)
            ->and(dhRoles($invited, $this->school))->toBe(['director', 'teacher'])
            ->and(UserRole::where('user_id', $invited->id)->whereNull('accepted_at')->exists())->toBeFalse()
            ->and(InvitationToken::where('email', 'invite@test.fr')->exists())->toBeFalse();
    });

    it('n\'écrase pas le mot de passe d\'un compte actif qui traîne un vieux jeton d\'invitation', function () {
        $actif = dhUser('actif@test.fr', 'Compte', 'Actif');
        dhGrant($actif, 'admin', $this->otherSchool);
        InvitationToken::create(['email' => 'actif@test.fr', 'token' => 'vieux', 'school_id' => $this->otherSchool->id, 'expires_at' => now()->subMonth()]);
        $hash = $actif->password;
        $actif->createToken('session');

        $token = dhInitiate($this->director, $this->school, 'actif@test.fr');

        $this->postJson('/api/director-handover/check', ['token' => $token])
            ->assertJsonPath('data.requires_password', false);
        $this->postJson('/api/director-handover/accept', ['token' => $token])->assertOk();

        expect($actif->fresh()->password)->toBe($hash)
            ->and($actif->tokens()->count())->toBe(1)
            ->and(dhRoles($actif, $this->school))->toBe(['director']);
    });

    it('échoue sans rien modifier si l\'émetteur n\'est plus directeur', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        UserRole::where('user_id', $this->director->id)->forceDelete();

        $this->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertStatus(409);

        expect(User::where('email', 'nouveau@test.fr')->exists())->toBeFalse()
            ->and(DB::table('director_handovers')->value('status'))->toBe('pending');
    });

    it('échoue si le destinataire est devenu l\'émetteur (e-mail modifié entre-temps)', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        DB::table('users')->where('id', $this->director->id)->update(['email' => 'nouveau@test.fr']);

        $this->postJson('/api/director-handover/accept', ['token' => $token])->assertStatus(409);

        expect(dhRoles($this->director, $this->school))->toBe(['director']);
    });

    it('refuse un lien expiré', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');
        DB::table('director_handovers')->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertNotFound();

        expect(dhRoles($this->director, $this->school))->toBe(['director']);
    });

    it('n\'utilise pas l\'utilisateur connecté : seul le jeton compte', function () {
        $intrus = dhUser('intrus@test.fr');
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');

        $this->actingAs($intrus, 'sanctum')->postJson('/api/director-handover/accept', [
            'token' => $token, 'first_name' => 'N', 'last_name' => 'D',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertOk();

        expect(dhRoles($intrus, $this->school))->toBe([])
            ->and(dhRoles(User::where('email', 'nouveau@test.fr')->first(), $this->school))->toBe(['director']);
    });
});

describe('déconnexion de l\'ancien directeur', function () {
    function dhBearer(string $token, School $school)
    {
        app('auth')->forgetGuards();

        return test()->withHeaders(['Authorization' => 'Bearer '.$token, 'X-School-Id' => (string) $school->id]);
    }

    it('révoque toutes les sessions de l\'ancien directeur à l\'acceptation', function () {
        $session = $this->director->createToken('navigateur')->plainTextToken;
        $this->director->createToken('mobile');
        dhBearer($session, $this->school)->getJson('/api/director-handover')->assertOk();

        dhAcceptAsNewUser(dhInitiate($this->director, $this->school, 'nouveau@test.fr', 'admin'));

        expect($this->director->tokens()->count())->toBe(0);
        dhBearer($session, $this->school)->getJson('/api/families')->assertUnauthorized();
    });

    it('ne touche pas aux sessions du nouveau directeur disposant déjà d\'un compte', function () {
        $prof = dhUser('prof@test.fr');
        dhGrant($prof, 'teacher', $this->school);
        $profSession = $prof->createToken('navigateur')->plainTextToken;

        $this->postJson('/api/director-handover/accept', ['token' => dhInitiate($this->director, $this->school, 'prof@test.fr')])->assertOk();

        expect($prof->tokens()->count())->toBe(1);
        dhBearer($profSession, $this->school)->getJson('/api/director-handover')->assertOk();
    });

    it('ne révoque rien en cas de refus ou d\'annulation', function () {
        $this->director->createToken('navigateur');

        $this->postJson('/api/director-handover/decline', ['token' => dhInitiate($this->director, $this->school, 'a@test.fr')])->assertOk();
        dhInitiate($this->director, $this->school, 'b@test.fr');
        $id = DB::table('director_handovers')->where('status', 'pending')->value('id');
        dhAs($this->director, $this->school)->postJson("/api/director-handover/{$id}/cancel")->assertOk();

        expect($this->director->tokens()->count())->toBe(1);
    });

    it('à la reconnexion, l\'ancien directeur n\'a plus que son nouveau rôle', function (string $outgoing, array $expectedSlugs) {
        $this->director->forceFill(['password' => 'ancienmdp1'])->save();
        dhAcceptAsNewUser(dhInitiate($this->director, $this->school, 'nouveau@test.fr', $outgoing));

        app('auth')->forgetGuards();
        // Ancien staff : il se connecte toujours, mais sans plus aucune école
        $login = $this->postJson('/api/login', ['email' => 'ancien.directeur@test.fr', 'password' => 'ancienmdp1'])->assertSuccessful();

        $roles = dhBearer($login->json('token'), $this->school)
            ->getJson("/api/users/{$this->director->id}/roles")
            ->assertOk()
            ->json('roles.schools');

        expect(collect($roles)->pluck('role_slug')->sort()->values()->all())
            ->toBe($expectedSlugs);

        $access = dhBearer($login->json('token'), $this->school)->getJson('/api/families');
        expect($access->status())->toBe($outgoing === 'none' ? 403 : 200);
    })->with([
        'devient admin' => ['admin', ['admin']],
        'devient registar' => ['registar', ['registar']],
        'quitte l\'école' => ['none', []],
    ]);
});

describe('refus', function () {
    it('refuse l\'invitation, prévient l\'émetteur et laisse la direction inchangée', function () {
        $token = dhInitiate($this->director, $this->school, 'nouveau@test.fr');

        $this->postJson('/api/director-handover/decline', ['token' => $token])->assertOk();

        expect(DB::table('director_handovers')->value('status'))->toBe('declined')
            ->and(dhRoles($this->director, $this->school))->toBe(['director'])
            ->and(User::where('email', 'nouveau@test.fr')->exists())->toBeFalse();

        Notification::assertSentTo($this->director, DirectorHandoverStatusNotification::class,
            fn ($n) => $n->action === 'declined');

        $this->postJson('/api/director-handover/accept', ['token' => $token])->assertNotFound();

        dhInitiate($this->director, $this->school, 'autre@test.fr');
    });
});
