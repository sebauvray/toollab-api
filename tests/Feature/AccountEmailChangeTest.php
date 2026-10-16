<?php
use App\Models\{User, AuditLog};
use App\Notifications\{AccountEmailConfirmation, AccountEmailNotice};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Notification};
uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['toollab.super_admin_emails' => ['root@email-test.test']]);
    $this->person = User::factory()->create(['email' => 'old@email-test.test', 'password' => 'old-password', 'access' => true, 'became_staff_at' => now()]);
    $this->root = User::factory()->create(['email' => 'root@email-test.test', 'password' => 'root-password', 'access' => true]);
});

function emailChangeToken(): string {
    $token = '';
    Notification::assertSentOnDemand(AccountEmailConfirmation::class, function ($notice) use (&$token) {
        $token = $notice->token;
        return true;
    });
    return $token;
}
function requestOwnEmail($test, string $email = 'new@email-test.test') {
    return $test->actingAs($test->person, 'sanctum')->postJson('/api/me/email-change', ['email' => $email, 'current_password' => 'old-password']);
}
function anonymousEmailConfirmation($test, string $token, array $extra = []) {
    app('auth')->forgetGuards();
    return $test->postJson('/api/account-email/confirm', ['token' => $token] + $extra);
}

it('ne change rien avant confirmation puis ferme les sessions et invalide le lien', function () {
    $this->person->createToken('session');
    DB::table('password_reset_tokens')->insert(['email' => $this->person->email, 'token' => Hash::make('reset'), 'created_at' => now()]);
    requestOwnEmail($this)->assertOk();
    expect($this->person->fresh()->email)->toBe('old@email-test.test');
    $token = emailChangeToken();
    expect(DB::table('account_email_changes')->value('token_hash'))->toBe(hash('sha256', $token))->not->toBe($token);
    $this->postJson('/api/account-email/check', ['token' => $token])->assertOk()->assertJsonPath('recovery', false);
    anonymousEmailConfirmation($this, $token)->assertOk();
    expect($this->person->fresh()->email)->toBe('new@email-test.test')
        ->and($this->person->tokens()->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(Hash::check('old-password', $this->person->fresh()->password))->toBeTrue();
    anonymousEmailConfirmation($this, $token)->assertUnprocessable();
    Notification::assertSentOnDemand(AccountEmailNotice::class, fn ($n, $channels, $recipient) => $n->completed && $recipient->routes['mail'] === 'old@email-test.test');
    expect(AuditLog::where('action', 'user.email_changed')->count())->toBe(1);
});

it('refuse un mauvais mot de passe', function () {
    $this->actingAs($this->person, 'sanctum')->postJson('/api/me/email-change', ['email' => 'new@email-test.test', 'current_password' => 'wrong'])->assertUnprocessable();
    Notification::assertNothingSent();
    expect(DB::table('account_email_changes')->count())->toBe(0);
});

it('refuse les adresses réservées, identiques ou déjà occupées', function (string $email) {
    User::factory()->create(['email' => 'taken@email-test.test']);
    requestOwnEmail($this, $email)->assertUnprocessable();
    Notification::assertNothingSent();
})->with(['root@email-test.test', 'ROOT@email-test.test', 'old@email-test.test', 'taken@email-test.test']);

it('recontrôle la disponibilité à la confirmation', function () {
    requestOwnEmail($this)->assertOk();
    $token = emailChangeToken();
    User::factory()->create(['email' => 'new@email-test.test']);
    anonymousEmailConfirmation($this, $token)->assertUnprocessable();
    expect($this->person->fresh()->email)->toBe('old@email-test.test');
});

it('refuse un lien expiré, un compte désactivé ou un mot de passe changé', function (string $state) {
    requestOwnEmail($this)->assertOk();
    $token = emailChangeToken();
    if ($state === 'expired') $this->travel(61)->minutes();
    if ($state === 'disabled') $this->person->update(['access' => false]);
    if ($state === 'password') $this->person->update(['password' => 'changed-password']);
    $response = anonymousEmailConfirmation($this, $token);
    expect($response->status())->toBeIn([403, 422]);
    expect($this->person->fresh()->email)->toBe('old@email-test.test');
})->with(['expired', 'disabled', 'password']);

it('une nouvelle demande invalide le lien précédent', function () {
    requestOwnEmail($this)->assertOk();
    $old = emailChangeToken();
    requestOwnEmail($this, 'next@email-test.test')->assertOk();
    anonymousEmailConfirmation($this, $old)->assertUnprocessable();
});

it('réserve la récupération au support et exige le mot de passe et le contrôle d’identité', function () {
    $uri = '/api/admin/users/'.$this->person->id.'/recover-email';
    $payload = ['email' => 'new@email-test.test', 'current_password' => 'root-password', 'reason' => 'Identité vérifiée par le support', 'identity_verified' => true];
    $this->actingAs($this->person, 'sanctum')->postJson($uri, $payload)->assertForbidden();
    $this->actingAs($this->root, 'sanctum')->postJson($uri, array_replace($payload, ['identity_verified' => false]))->assertUnprocessable();
    $this->postJson($uri, array_replace($payload, ['current_password' => 'wrong']))->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->postJson($uri, $payload)->assertOk();
    $token = emailChangeToken();
    anonymousEmailConfirmation($this, $token)->assertUnprocessable();
    anonymousEmailConfirmation($this, $token, ['password' => 'recovered-password', 'password_confirmation' => 'recovered-password'])->assertOk();
    expect(Hash::check('recovered-password', $this->person->fresh()->password))->toBeTrue();
    expect(AuditLog::where('action', 'user.email_recovery_requested')->sole()->meta['reason'])->toBe($payload['reason']);
});

it('ne modifie pas les comptes super-admin ou purement familiaux', function (string $kind) {
    $user = $kind === 'root' ? $this->root : User::factory()->create(['password' => 'root-password', 'access' => true, 'became_staff_at' => null]);
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/me/email-change', ['email' => 'new@email-test.test', 'current_password' => 'root-password']);
    expect($response->status())->toBeIn([403, 422]);
    Notification::assertNothingSent();
})->with(['root', 'family']);

it('ne permet pas de choisir un autre compte à la place du sien', function () {
    $this->actingAs($this->person, 'sanctum')->postJson('/api/me/email-change', [
        'email' => 'new@email-test.test', 'current_password' => 'old-password', 'user_id' => $this->root->id,
    ])->assertOk();
    expect(DB::table('account_email_changes')->value('user_id'))->toBe($this->person->id);
});


it('bloque les demandes pendant une session en tant que', function () {
    $token = $this->person->createToken(App\Models\Impersonation::TOKEN_NAME)->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/me/email-change', ['email' => 'new@email-test.test', 'current_password' => 'old-password'])
        ->assertForbidden()->assertJsonPath('impersonation_read_only', true);
    Notification::assertNothingSent();
});

it('rend le mail de confirmation et ne met pas le jeton dans la query string', function () {
    $notification = new AccountEmailConfirmation(str_repeat('a', 64), false);
    $mail = $notification->toMail(new Illuminate\Notifications\AnonymousNotifiable());
    expect($mail->actionUrl)->toContain('/confirm-email#token=')->not->toContain('?token=');
    expect((string) $mail->render())->toContain('Confirmer mon adresse');
});
