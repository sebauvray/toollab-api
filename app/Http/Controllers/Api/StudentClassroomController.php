<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\FamilyController;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use App\Models\Classroom;
use App\Models\Family;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentClassroomController extends Controller
{
    public function enroll(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:users,id',
            'classroom_id' => 'required|exists:classrooms,id',
            'family_id' => 'required|exists:families,id'
        ]);

        DB::beginTransaction();

        try {
            $classroom = Classroom::lockForUpdate()->find($request->classroom_id);
            $family = Family::find($request->family_id);
            $student = User::find($request->student_id);

            if (!$classroom || !$family) {
                return response()->json(['status' => 'error', 'message' => 'Ressource introuvable'], 404);
            }

            if (!FamilyController::callerCanAccessFamily($family)) {
                return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
            }

            $isStudentInFamily = UserRole::where('user_id', $student->id)
                ->where('roleable_type', 'family')
                ->where('roleable_id', $family->id)
                ->whereHas('role', function($q) {
                    $q->where('slug', 'student');
                })
                ->exists();

            if (!$isStudentInFamily) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'L\'élève ne fait pas partie de cette famille'
                ], 400);
            }

            if ($classroom->isFull()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette classe est complète'
                ], 400);
            }

            // withTrashed : une inscription supprimée (famille mise à la corbeille,
            // ou changement de classe) occupe toujours l'index
            // UNIQUE(student_id, classroom_id). Sans ce filet, le create() plus bas
            // se cognerait à un doublon SQL au lieu de réutiliser la ligne.
            $existingEnrollment = StudentClassroom::withTrashed()
                ->where('student_id', $request->student_id)
                ->where('classroom_id', $request->classroom_id)
                ->first();

            if ($existingEnrollment && ! $existingEnrollment->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'L\'élève est déjà inscrit dans cette classe'
                ], 400);
            }

            $existingTypeEnrollment = StudentClassroom::where('student_id', $request->student_id)
                ->whereHas('classroom', function($query) use ($classroom) {
                    $query->where('type', $classroom->type)
                        ->where('cursus_id', $classroom->cursus_id);
                })
                ->where('status', 'active')
                ->first();

            if ($existingTypeEnrollment) {
                $existingTypeEnrollment->delete();

                UserRole::where('user_id', $request->student_id)
                    ->where('roleable_type', 'classroom')
                    ->where('roleable_id', $existingTypeEnrollment->classroom_id)
                    ->delete();
            }

            $snapshot = $this->buildTarifSnapshot($classroom);

            $enrollmentData = [
                'student_id' => $request->student_id,
                'classroom_id' => $request->classroom_id,
                'family_id' => $request->family_id,
                'status' => 'active',
                'enrollment_date' => now(),
                'tarif_snapshot' => $snapshot,
            ];

            if ($existingEnrollment) {
                // Ligne supprimée réutilisée : on la ressuscite et on la remet à
                // jour (nouvelle famille, nouveau snapshot tarifaire).
                $existingEnrollment->restore();
                $existingEnrollment->update($enrollmentData);
                $enrollment = $existingEnrollment;
            } else {
                $enrollment = StudentClassroom::create($enrollmentData);
            }

            $studentRole = Role::where('slug', 'student')->first();
            if ($studentRole) {
                // withTrashed pour la même raison que l'inscription : l'index
                // UNIQUE(user_id, role_id, roleable_type, roleable_id) retient
                // aussi les lignes supprimées.
                $existingClassroomRole = UserRole::withTrashed()
                    ->where('user_id', $student->id)
                    ->where('role_id', $studentRole->id)
                    ->where('roleable_type', 'classroom')
                    ->where('roleable_id', $classroom->id)
                    ->first();

                if (!$existingClassroomRole) {
                    UserRole::create([
                        'user_id' => $student->id,
                        'role_id' => $studentRole->id,
                        'roleable_type' => 'classroom',
                        'roleable_id' => $classroom->id
                    ]);
                } elseif ($existingClassroomRole->trashed()) {
                    $existingClassroomRole->restore();
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Élève inscrit avec succès',
                'data' => $enrollment->load(['classroom.schedules', 'classroom.level'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            Log::error('StudentClassroom.enroll failed', [
                'student_id' => $request->student_id,
                'classroom_id' => $request->classroom_id,
                'family_id' => $request->family_id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de l\'inscription',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function unenroll(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:users,id',
            'classroom_id' => 'required|exists:classrooms,id'
        ]);

        DB::beginTransaction();

        try {
            $classroom = Classroom::find($request->classroom_id);
            if (!$classroom) {
                return response()->json(['status' => 'error', 'message' => 'Ressource introuvable'], 404);
            }

            $enrollment = StudentClassroom::where('student_id', $request->student_id)
                ->where('classroom_id', $request->classroom_id)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Inscription non trouvée'
                ], 404);
            }

            $family = Family::find($enrollment->family_id);
            if (!$family || !FamilyController::callerCanAccessFamily($family)) {
                return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
            }

            $enrollment->delete();

            UserRole::where('user_id', $request->student_id)
                ->where('roleable_type', 'classroom')
                ->where('roleable_id', $request->classroom_id)
                ->delete();

            // La décision de fin d'année suit l'inscription : sans ça elle
            // resurgirait à la réinscription dans la même classe.
            StudentYearOutcome::forgetForEnrollments(
                [(int) $request->student_id],
                [(int) $request->classroom_id]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Inscription supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Snapshot du tarif et des réductions du cursus au moment de l'inscription.
     * Permet de figer le prix promis à la famille — modifier le Tarif après
     * coup n'affecte pas les inscriptions déjà snapshotées.
     */
    private function buildTarifSnapshot(Classroom $classroom): array
    {
        $cursus = $classroom->cursus()->with([
            'tarif',
            'reductionsFamiliales',
            'reductionsMultiCursusBeneficiaire',
        ])->first();

        if (!$cursus) {
            return [];
        }

        return [
            'cursus_id' => $cursus->id,
            'school_year_id' => $classroom->school_year_id,
            'tarif_base' => $cursus->tarif ? (int) $cursus->tarif->prix : null,
            'reductions_familiales' => $cursus->reductionsFamiliales->map(fn ($r) => [
                'nombre_eleves_min' => (int) $r->nombre_eleves_min,
                'pourcentage_reduction' => (float) $r->pourcentage_reduction,
            ])->values()->all(),
            'reductions_multi_cursus' => $cursus->reductionsMultiCursusBeneficiaire->map(fn ($r) => [
                'cursus_requis_id' => (int) $r->cursus_requis_id,
                'pourcentage_reduction' => (float) $r->pourcentage_reduction,
            ])->values()->all(),
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    public function getFamilyEnrollments($familyId)
    {
        try {
            $family = Family::findOrFail($familyId);

            if (!FamilyController::callerCanAccessFamily($family)) {
                return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
            }

            $students = UserRole::where('roleable_type', 'family')
                ->where('roleable_id', $familyId)
                ->whereHas('role', function($q) {
                    $q->where('slug', 'student');
                })
                ->with(['user.studentClassrooms.classroom.schedules', 'user.studentClassrooms.classroom.level'])
                ->get();

            $enrollments = [];

            foreach ($students as $studentRole) {
                $student = $studentRole->user;
                $studentEnrollments = [];

                foreach ($student->studentClassrooms as $enrollment) {
                    if ($enrollment->status === 'active' && $enrollment->family_id == $familyId) {
                        $studentEnrollments[] = [
                            'classroom_id' => $enrollment->classroom_id,
                            'classroom' => $enrollment->classroom,
                            'enrollment_date' => $enrollment->enrollment_date
                        ];
                    }
                }

                $enrollments[$student->id] = $studentEnrollments;
            }

            return response()->json([
                'status' => 'success',
                'data' => $enrollments
            ]);

        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
