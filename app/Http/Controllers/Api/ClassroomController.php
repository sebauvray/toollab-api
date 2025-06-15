<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Classroom::with(['cursus', 'level', 'schedules.teacher']);

            if ($request->has('cursus_id')) {
                $query->where('cursus_id', $request->cursus_id);
            }

            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }

            if ($request->has('available_only') && $request->available_only) {
                $query->whereRaw('size > (SELECT COUNT(*) FROM student_classrooms WHERE classroom_id = classrooms.id AND status = "active")');
            }

            $perPage = $request->get('per_page', 10);
            $classrooms = $query->paginate($perPage);

            $formattedClassrooms = $classrooms->map(function ($classroom) {
                $schedule = $classroom->schedules->first();

                return [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'cursus_id' => $classroom->cursus_id,
                    'cursus' => $classroom->cursus,
                    'level_id' => $classroom->level_id,
                    'level' => $classroom->level,
                    'size' => $classroom->size,
                    'gender' => $classroom->gender,
                    'type' => $classroom->type,
                    'student_count' => $classroom->student_count,
                    'available_spots' => $classroom->available_spots,
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
                    'items' => $formattedClassrooms,
                    'pagination' => [
                        'total' => $classrooms->total(),
                        'per_page' => $classrooms->perPage(),
                        'current_page' => $classrooms->currentPage(),
                        'total_pages' => $classrooms->lastPage()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la récupération des classes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $classroom = Classroom::with(['cursus', 'level', 'schedules.teacher', 'activeStudents'])
                ->findOrFail($id);

            $schedule = $classroom->schedules->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'cursus' => $classroom->cursus,
                    'level' => $classroom->level,
                    'size' => $classroom->size,
                    'gender' => $classroom->gender,
                    'type' => $classroom->type,
                    'student_count' => $classroom->student_count,
                    'available_spots' => $classroom->available_spots,
                    'students' => $classroom->activeStudents,
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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'cursus_id' => 'required|exists:cursus,id',
            'level_id' => 'nullable|exists:levels,id',
            'size' => 'required|integer|min:1',
            'gender' => 'required|in:M,F,Mixte',
            'type' => 'nullable|string',
            'school_id' => 'required|exists:schools,id',
            'schedule' => 'nullable|array',
            'schedule.day' => 'required_with:schedule|in:Lundi,Mardi,Mercredi,Jeudi,Vendredi,Samedi,Dimanche',
            'schedule.start_time' => 'required_with:schedule|date_format:H:i',
            'schedule.end_time' => 'required_with:schedule|date_format:H:i|after:schedule.start_time',
            'schedule.teacher_id' => 'nullable|exists:users,id'
        ]);

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
                'years' => date('Y')
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
                'message' => 'Une erreur est survenue lors de la création de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'level_id' => 'sometimes|nullable|exists:levels,id',
            'size' => 'sometimes|integer|min:1',
            'gender' => 'sometimes|in:M,F,Mixte',
            'type' => 'sometimes|string',
            'schedule' => 'sometimes|array',
            'schedule.day' => 'required_with:schedule|in:Lundi,Mardi,Mercredi,Jeudi,Vendredi,Samedi,Dimanche',
            'schedule.start_time' => 'required_with:schedule|date_format:H:i',
            'schedule.end_time' => 'required_with:schedule|date_format:H:i|after:schedule.start_time',
            'schedule.teacher_id' => 'nullable|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $classroom = Classroom::findOrFail($id);

            $classroom->update($request->only(['name', 'level_id', 'size', 'gender', 'type']));

            if ($request->has('schedule')) {
                $schedule = $classroom->schedules()->first();

                if ($schedule) {
                    $schedule->update([
                        'day' => $request->schedule['day'],
                        'start_time' => $request->schedule['start_time'],
                        'end_time' => $request->schedule['end_time'],
                        'teacher_id' => $request->schedule['teacher_id'] ?? null
                    ]);
                } else {
                    $classroom->schedules()->create([
                        'day' => $request->schedule['day'],
                        'start_time' => $request->schedule['start_time'],
                        'end_time' => $request->schedule['end_time'],
                        'teacher_id' => $request->schedule['teacher_id'] ?? null
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe mise à jour avec succès',
                'data' => $classroom->load(['cursus', 'level', 'schedules.teacher'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $classroom = Classroom::findOrFail($id);

            if ($classroom->student_count > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer une classe avec des élèves inscrits'
                ], 400);
            }

            $classroom->schedules()->delete();
            $classroom->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe supprimée avec succès'
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
}
