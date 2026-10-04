<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DirectorHandoverException;
use App\Http\Controllers\Controller;
use App\Models\DirectorHandover;
use App\Models\School;
use App\Services\DirectorHandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DirectorHandoverController extends Controller
{
    public function __construct(private DirectorHandoverService $service)
    {
    }

    public function current(Request $request)
    {
        if ($denied = $this->denyUnlessDirector($request)) {
            return $denied;
        }

        $handover = $this->service->currentFor(currentSchoolId());

        return response()->json([
            'status' => 'success',
            'message' => '',
            'data' => $handover ? $this->present($handover) : null,
        ]);
    }

    public function store(Request $request)
    {
        if ($denied = $this->denyUnlessDirector($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'outgoing_role' => ['required', 'string', Rule::in(DirectorHandover::OUTGOING_ROLES)],
            'remove_teacher_role' => 'sometimes|boolean',
        ], [
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email' => 'L\'adresse e-mail est invalide.',
            'email.max' => 'L\'adresse e-mail est trop longue.',
            'outgoing_role.required' => 'Choisissez votre rôle après la passation.',
            'outgoing_role.in' => 'Le rôle choisi est invalide.',
            'remove_teacher_role.boolean' => 'Le choix concernant votre rôle de professeur est invalide.',
        ]);

        return $this->run('store', function () use ($request, $validated) {
            $handover = $this->service->initiate(
                School::findOrFail(currentSchoolId()),
                $request->user(),
                $validated['email'],
                $validated['outgoing_role'],
                (bool) ($validated['remove_teacher_role'] ?? false)
            );

            return response()->json([
                'status' => 'success',
                'message' => 'L\'invitation de passation a été envoyée à '.$handover->email.'.',
                'data' => $this->present($handover),
            ], 201);
        });
    }

    public function resend(Request $request, int $id)
    {
        if ($denied = $this->denyUnlessDirector($request)) {
            return $denied;
        }

        return $this->run('resend', function () use ($request, $id) {
            $handover = $this->service->resend($id, School::findOrFail(currentSchoolId()), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'L\'invitation a été renvoyée à '.$handover->email.'.',
                'data' => $this->present($handover),
            ]);
        });
    }

    public function cancel(Request $request, int $id)
    {
        if ($denied = $this->denyUnlessDirector($request)) {
            return $denied;
        }

        return $this->run('cancel', function () use ($id) {
            $this->service->cancel($id, currentSchoolId());

            return response()->json([
                'status' => 'success',
                'message' => 'La passation a été annulée.',
                'data' => null,
            ]);
        });
    }

    public function check(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|max:128']);

        $handover = $this->service->findActionable($validated['token']);
        if (!$handover) {
            return $this->invalidLink();
        }

        return response()->json([
            'status' => 'success',
            'message' => '',
            'data' => $this->service->describe($handover),
        ]);
    }

    public function accept(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|max:128']);

        return $this->run('accept', function () use ($request, $validated) {
            $handover = $this->service->accept(
                $validated['token'],
                $request->only(['first_name', 'last_name', 'password', 'password_confirmation'])
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Vous êtes désormais directeur de l\'établissement.',
                'data' => [
                    'school_id' => $handover->school_id,
                    'email' => $handover->email,
                ],
            ]);
        });
    }

    public function decline(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|max:128']);

        return $this->run('decline', function () use ($validated) {
            $this->service->decline($validated['token']);

            return response()->json([
                'status' => 'success',
                'message' => 'L\'invitation a été refusée.',
                'data' => null,
            ]);
        });
    }

    private function run(string $action, \Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (DirectorHandoverException $e) {
            Log::warning('DirectorHandover.'.$action.': refused', [
                'caller_id' => auth()->id(),
                'school_id' => currentSchoolId(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->status());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DirectorHandover.'.$action.' failed', [
                'caller_id' => auth()->id(),
                'school_id' => currentSchoolId(),
                'exception' => $e,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Une erreur est survenue'], 500);
        }
    }

    private function denyUnlessDirector(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $schoolId = currentSchoolId();

        if ($user && $schoolId !== null && $this->service->isDirectorOf($user->id, $schoolId)) {
            return null;
        }

        Log::warning('DirectorHandover: caller is not director', [
            'caller_id' => $user?->id,
            'school_id' => $schoolId,
            'path' => $request->path(),
        ]);

        return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
    }

    private function invalidLink(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Le lien de passation est invalide ou a expiré.',
        ], 404);
    }

    private function present(DirectorHandover $handover): array
    {
        return [
            'id' => $handover->id,
            'email' => $handover->email,
            'outgoing_role' => $handover->outgoing_role,
            'remove_teacher_role' => $handover->remove_teacher_role,
            'status' => $handover->status === DirectorHandover::STATUS_PENDING && $handover->isExpired()
                ? DirectorHandover::STATUS_EXPIRED
                : $handover->status,
            'expires_at' => $handover->expires_at,
            'created_at' => $handover->created_at,
        ];
    }
}
