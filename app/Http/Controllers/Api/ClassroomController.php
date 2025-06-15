<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassroomRequest;
use App\Http\Requests\UpdateClassroomRequest;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        $query = Classroom::with(['cursus', 'level', 'activeStudents', 'schedules.teacher']);

        if ($request->has('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        if ($request->has('cursus_id')) {
            $query->where('cursus_id', $request->cursus_id);
        }

        $perPage = $request->get('per_page', 10);
        $classrooms = $query->paginate($perPage);

        $items = $classrooms->items();
        $formattedItems = collect($items)->map(function ($classroom) {
            $schedule = $classroom->schedules->first();

            return [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'cursus' => $classroom->cursus?->name ?? 'N/A',
                'level' => $classroom->level?->name ?? 'N/A',
                'gender' => $classroom->gender,
                'size' => $classroom->size,
                'studentCount' => $classroom->student_count,
                'availableSpots' => $classroom->available_spots,
                'schedule' => $schedule ? [
                    'day' => $schedule->day,
                    'time' => $schedule->formatted_time,
                    'teacher' => $schedule->teacher ? [
                        'id' => $schedule->teacher->id,
                        'name' => $schedule->teacher->first_name . ' ' . $schedule->teacher->last_name
                    ] : null
                ] : null
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $formattedItems,
                'pagination' => [
                    'total' => $classrooms->total(),
                    'per_page' => $classrooms->perPage(),
                    'current_page' => $classrooms->currentPage(),
                    'total_pages' => $classrooms->lastPage()
                ]
            ]
        ]);
    }

    public function show($id)
    {
        try {
            $classroom = Classroom::with(['cursus', 'level', 'activeStudents', 'schedules.teacher'])
                ->findOrFail($id);

            $schedule = $classroom->schedules->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'cursus' => $classroom->cursus?->name ?? 'N/A',
                    'level' => $classroom->level?->name ?? 'N/A',
                    'gender' => $classroom->gender,
                    'size' => $classroom->size,
                    'studentCount' => $classroom->student_count,
                    'availableSpots' => $classroom->available_spots,
                    'schedule' => $schedule ? [
                        'day' => $schedule->day,
                        'time' => $schedule->formatted_time,
                        'teacher' => $schedule->teacher
                    ] : null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe non trouvée',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function store(StoreClassroomRequest $request)
    {
        DB::beginTransaction();

        try {
            $classroom = Classroom::create([
                'name' => $request->name,
                'cursus_id' => $request->cursus_id,
                'level_id' => $request->level_id,
                'size' => $request->size,
                'gender' => $request->gender,
                'type' => $request->type ?? 'Standard',
                'school_id' => $request->school_id,
                'years' => $request->years ?? date('Y')
            ]);

            if ($request->has('schedule')) {
                $classroom->schedules()->create([
                    'day' => $request->schedule['day'],
                    'start_time' => $request->schedule['start_time'],
                    'end_time' => $request->schedule['end_time'],
                    'teacher_id' => $request->schedule['teacher_id'] ?? null
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe créée avec succès',
                'data' => $classroom->load(['cursus', 'level', 'schedules.teacher'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateClassroomRequest $request, $id)
    {
        DB::beginTransaction();

        try {
            $classroom = Classroom::findOrFail($id);

            $classroom->update($request->validated());

            if ($request->has('schedule')) {
                $classroom->schedules()->delete();

                $classroom->schedules()->create([
                    'day' => $request->schedule['day'],
                    'start_time' => $request->schedule['start_time'],
                    'end_time' => $request->schedule['end_time'],
                    'teacher_id' => $request->schedule['teacher_id'] ?? null
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe mise à jour avec succès',
                'data' => $classroom->fresh()->load(['cursus', 'level', 'schedules.teacher'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $classroom = Classroom::findOrFail($id);

            $classroom->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function addStudent(Request $request, $id)
    {
        $request->validate([
            'student_id' => 'required|exists:users,id',
            'family_id' => 'required|exists:families,id'
        ]);

        try {
            $classroom = Classroom::findOrFail($id);

            if ($classroom->isFull()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La classe est complète'
                ], 400);
            }

            $classroom->students()->attach($request->student_id, [
                'family_id' => $request->family_id,
                'status' => 'active',
                'enrollment_date' => now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Élève ajouté avec succès à la classe'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout de l\'élève',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removeStudent(Request $request, $id, $studentId)
    {
        try {
            $classroom = Classroom::findOrFail($id);

            $classroom->students()->detach($studentId);

            return response()->json([
                'status' => 'success',
                'message' => 'Élève retiré de la classe avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du retrait de l\'élève',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
