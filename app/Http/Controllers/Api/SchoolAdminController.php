<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Notifications\DirectorMessageNotification;
use App\Notifications\SchoolStatusNotification;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Actions rapides du super-admin sur une école : suspendre, réactiver, contacter le directeur. */
class SchoolAdminController extends Controller
{
    public function suspend(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
            'notify_director' => 'boolean',
        ]);

        if ($school->isSuspended()) {
            return response()->json(['message' => 'Cette école est déjà suspendue.'], 409);
        }

        $school->forceFill([
            'access' => false,
            'suspended_at' => now(),
            'suspension_reason' => $validated['reason'],
        ])->save();

        $notified = $this->notifyStatus($request, $school, 'suspended', $validated['reason'], $validated['notify_director'] ?? true);
        Audit::log('school.suspended', $school->id, $school, ['reason' => $validated['reason'], 'director_notified' => $notified]);

        return response()->json($this->presentStatus($school, $notified));
    }

    public function reactivate(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate(['notify_director' => 'boolean']);

        if (! $school->isSuspended()) {
            return response()->json(['message' => "Cette école n'est pas suspendue."], 409);
        }

        $school->forceFill(['access' => true, 'suspended_at' => null, 'suspension_reason' => null])->save();

        $notified = $this->notifyStatus($request, $school, 'reactivated', null, $validated['notify_director'] ?? true);
        Audit::log('school.reactivated', $school->id, $school, ['director_notified' => $notified]);

        return response()->json($this->presentStatus($school, $notified));
    }

    public function contactDirector(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|min:3|max:150',
            'message' => 'required|string|min:3|max:5000',
        ]);

        $director = $school->director();
        if (! $director) {
            return response()->json(['message' => "Cette école n'a pas de directeur en poste."], 422);
        }

        $admin = $request->user();
        $director->notify(new DirectorMessageNotification(
            $school->name,
            $validated['subject'],
            $validated['message'],
            trim(($admin->first_name ?? '').' '.($admin->last_name ?? '')) ?: 'Support Toollab',
            $admin->email,
        ));

        Audit::log('school.director_contacted', $school->id, $director, ['subject' => $validated['subject']]);

        return response()->json(['message' => "Message envoyé à {$director->email}.", 'sent_to' => $director->email]);
    }

    private function notifyStatus(Request $request, School $school, string $status, ?string $reason, bool $notify): bool
    {
        $director = $notify ? $school->director() : null;
        if (! $director) {
            return false;
        }

        $director->notify(new SchoolStatusNotification($school->name, $status, $reason, $request->user()->email));

        return true;
    }

    private function presentStatus(School $school, bool $notified): array
    {
        return [
            'id' => $school->id,
            'access' => (bool) $school->access,
            'suspended_at' => $school->suspended_at,
            'suspension_reason' => $school->suspension_reason,
            'director_notified' => $notified,
        ];
    }
}
