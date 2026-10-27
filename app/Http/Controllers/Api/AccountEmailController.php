<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\{AccountEmailConfirmation, AccountEmailNotice};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Notification};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class AccountEmailController extends Controller
{
    public function requestChange(Request $request) { return $this->start($request, $request->user(), false); }
    public function recover(Request $request, User $user) { return $this->start($request, $user, true); }

    private function eligible(User $user): void
    {
        abort_unless($user->access && $user->canLogIn(), 403, 'Ce compte ne peut pas changer son adresse de connexion.');
        if (in_array(strtolower($user->email), array_map('strtolower', config('toollab.super_admin_emails', [])), true)) {
            throw ValidationException::withMessages(['email' => 'Les adresses super-admin se modifient dans la configuration de la plateforme.']);
        }
    }

    private function checkEmail(string $email, User $user): void
    {
        $reserved = array_map('strtolower', config('toollab.super_admin_emails', []));
        if (in_array(strtolower($email), $reserved, true) || strcasecmp($email, $user->email) === 0
            || User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Cette adresse ne peut pas être utilisée.']);
        }
    }

    private function start(Request $request, User $user, bool $recovery)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'current_password' => 'required|string',
            'reason' => $recovery ? 'required|string|min:10|max:1000' : 'nullable',
            'identity_verified' => $recovery ? 'required|accepted' : 'nullable',
        ]);
        abort_unless($request->user()->access, 403);
        if (!Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }
        $email = strtolower(trim($data['email']));
        $token = Str::random(64);
        $oldEmail = DB::transaction(function () use ($user, $email, $token, $recovery, $data) {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->eligible($target);
            if (!$recovery && !Hash::check($data['current_password'], $target->password)) {
                throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
            }
            $this->checkEmail($email, $target);
            DB::table('account_email_changes')->updateOrInsert(['user_id' => $target->id], [
                'old_email' => $target->email, 'new_email' => $email,
                'token_hash' => hash('sha256', $token),
                'password_fingerprint' => hash('sha256', (string) $target->password),
                'recovery' => $recovery, 'expires_at' => now()->addHour(),
            ]);
            return $target->email;
        });
        try {
            Notification::route('mail', $email)->notify(new AccountEmailConfirmation($token, $recovery));
        } catch (\Throwable $e) {
            DB::table('account_email_changes')->where('token_hash', hash('sha256', $token))->delete();
            report($e);
            return response()->json(['message' => 'L’email n’a pas pu être envoyé. Réessayez plus tard.'], 503);
        }
        Audit::log($recovery ? 'user.email_recovery_requested' : 'user.email_change_requested', null, $user,
            ['new_email' => $email, 'reason' => $recovery ? $data['reason'] : null]);
        $this->notice($oldEmail, false);
        return response()->json(['message' => 'Un lien valable 60 minutes a été envoyé à la nouvelle adresse. L’adresse actuelle reste inchangée jusqu’à confirmation.']);
    }

    private function pending(Request $request)
    {
        $request->validate(['token' => 'required|string|size:64']);
        $change = DB::table('account_email_changes')->where('token_hash', hash('sha256', $request->token))->first();
        $user = $change ? User::find($change->user_id) : null;
        $this->validatePending($change, $user);
        return $change;
    }

    private function validatePending($change, ?User $user): void
    {
        if (!$change || !$user || now()->greaterThanOrEqualTo($change->expires_at)
            || $user->email !== $change->old_email
            || !hash_equals($change->password_fingerprint, hash('sha256', (string) $user->password))) {
            throw ValidationException::withMessages(['token' => 'Ce lien est invalide ou expiré. Demandez un nouveau lien.']);
        }
        $this->eligible($user);
    }

    public function check(Request $request)
    {
        $change = $this->pending($request);
        return response()->json(['user_id' => $change->user_id, 'email' => $change->new_email, 'recovery' => (bool) $change->recovery]);
    }

    public function confirm(Request $request)
    {
        $change = $this->pending($request);
        if ($change->recovery) {
            $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        }
        try {
            $result = DB::transaction(function () use ($change, $request) {
                $user = User::whereKey($change->user_id)->lockForUpdate()->firstOrFail();
                $pending = DB::table('account_email_changes')->where('user_id', $user->id)->lockForUpdate()->first();
                if (!$pending || !hash_equals($pending->token_hash, hash('sha256', $request->token))) {
                    throw ValidationException::withMessages(['token' => 'Ce lien n’est plus valable.']);
                }
                $this->validatePending($pending, $user);
                $this->checkEmail($pending->new_email, $user);
                $oldEmail = $user->email;
                $user->email = $pending->new_email;
                $user->email_verified_at = now();
                $user->remember_token = Str::random(60);
                if ($pending->recovery) $user->password = Hash::make($request->password);
                $user->save();
                $user->tokens()->delete();
                DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $user->email])->delete();
                DB::table('invitation_tokens')->where('email', $oldEmail)->delete();
                DB::table('account_email_changes')->where('user_id', $user->id)->delete();
                Audit::log('user.email_changed', null, $user, ['old_email' => $oldEmail, 'recovery' => (bool) $pending->recovery], $user);
                return [$oldEmail, $user->email];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'Cette adresse ne peut plus être utilisée.']);
        }
        foreach ($result as $email) $this->notice($email, true);
        return response()->json(['message' => 'Votre adresse a été modifiée. Reconnectez-vous avec votre nouvelle adresse.']);
    }

    private function notice(string $email, bool $completed): void
    {
        try { Notification::route('mail', $email)->notify(new AccountEmailNotice($completed)); }
        catch (\Throwable $e) { report($e); }
    }
}
