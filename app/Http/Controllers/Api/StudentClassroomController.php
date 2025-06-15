<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentClassroom;
use App\Models\Classroom;
use App\Models\Family;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $classroom = Classroom::find($request->classroom_id);
            $student = User::find($request->student_id);
            $family = Family::find($request->family_id);

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

            $existingEnrollment = StudentClassroom::where('student_id', $request->student_id)
                ->where('classroom_id', $request->classroom_id)
                ->first();

            if ($existingEnrollment) {
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
                return response()->json([
                    'status' => 'error',
                    'message' => 'L\'élève est déjà inscrit dans une classe de type ' . $classroom->type
                ], 400);
            }

            $enrollment = StudentClassroom::create([
                'student_id' => $request->student_id,
                'classroom_id' => $request->classroom_id,
                'family_id' => $request->family_id,
                'status' => 'active',
                'enrollment_date' => now()
            ]);

            $studentRole = Role::where('slug', 'student')->first();
            if ($studentRole) {
                $existingClassroomRole = UserRole::where('user_id', $student->id)
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
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Élève inscrit avec succès',
                'data' => $enrollment->load(['classroom.schedules.teacher', 'classroom.level'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de l\'inscription',
                'error' => $e->getMessage()
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
            $enrollment = StudentClassroom::where('student_id', $request->student_id)
                ->where('classroom_id', $request->classroom_id)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Inscription non trouvée'
                ], 404);
            }

            $enrollment->delete();

            UserRole::where('user_id', $request->student_id)
                ->where('roleable_type', 'classroom')
                ->where('roleable_id', $request->classroom_id)
                ->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Inscription supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFamilyEnrollments($familyId)
    {
        try {
            $family = Family::findOrFail($familyId);

            $students = UserRole::where('roleable_type', 'family')
                ->where('roleable_id', $familyId)
                ->whereHas('role', function($q) {
                    $q->where('slug', 'student');
                })
                ->with(['user.studentClassrooms.classroom.schedules.teacher', 'user.studentClassrooms.classroom.level'])
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
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
