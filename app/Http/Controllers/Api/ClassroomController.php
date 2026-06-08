<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassroomRequest;
use App\Http\Requests\UpdateClassroomRequest;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        $query = Classroom::with(['cursus', 'level', 'activeStudents', 'schedules.teacher']);

        if ($request->has('cursus_id')) {
            $query->where('cursus_id', $request->cursus_id);
        }

        $perPage = $request->get('per_page', 10);
        $classrooms = $query->paginate($perPage);

        $items = $classrooms->items();
        $formattedItems = collect($items)->map(function ($classroom) {
            return [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'cursus' => $classroom->cursus?->name ?? 'N/A',
                'level' => $classroom->level,
                'level_id' => $classroom->level_id,
                'gender' => $classroom->gender,
                'size' => $classroom->size,
                'student_count' => $classroom->student_count,
                'available_spots' => $classroom->available_spots,
                'telegram_link' => $classroom->telegram_link,
                'schedules' => $classroom->schedules->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'day' => $schedule->day,
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                        'formatted_time' => $schedule->formatted_time,
                        'teacher_name' => $schedule->teacher_name,
                        'teacher_id' => $schedule->teacher_id,
                        'teacher' => $schedule->teacher ? [
                            'id' => $schedule->teacher->id,
                            'first_name' => $schedule->teacher->first_name,
                            'last_name' => $schedule->teacher->last_name,
                        ] : null,
                    ];
                })
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

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'cursus' => $classroom->cursus?->name ?? 'N/A',
                    'level' => $classroom->level,
                    'level_id' => $classroom->level_id,
                    'gender' => $classroom->gender,
                    'size' => $classroom->size,
                    'student_count' => $classroom->student_count,
                    'available_spots' => $classroom->available_spots,
                    'telegram_link' => $classroom->telegram_link,
                    'schedules' => $classroom->schedules->map(function ($schedule) {
                        return [
                            'id' => $schedule->id,
                            'day' => $schedule->day,
                            'start_time' => $schedule->start_time,
                            'end_time' => $schedule->end_time,
                            'formatted_time' => $schedule->formatted_time,
                            'teacher_name' => $schedule->teacher_name,
                            'teacher_id' => $schedule->teacher_id,
                            'teacher' => $schedule->teacher ? [
                                'id' => $schedule->teacher->id,
                                'first_name' => $schedule->teacher->first_name,
                                'last_name' => $schedule->teacher->last_name,
                            ] : null,
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe non trouvée',
                'error' => 'Une erreur est survenue'
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
                'years' => $request->years ?? date('Y'),
                'telegram_link' => $request->telegram_link
            ]);

            if ($request->has('schedules') && is_array($request->schedules)) {
                foreach ($request->schedules as $scheduleData) {
                    $classroom->schedules()->create([
                        'day' => $scheduleData['day'],
                        'start_time' => $scheduleData['start_time'],
                        'end_time' => $scheduleData['end_time'],
                        'teacher_name' => $scheduleData['teacher_name'] ?? null,
                        'teacher_id' => $scheduleData['teacher_id'] ?? null,
                    ]);
                }
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
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function update(UpdateClassroomRequest $request, $id)
    {
        DB::beginTransaction();

        try {
            $classroom = Classroom::findOrFail($id);

            $classroom->update([
                'name' => $request->name,
                'cursus_id' => $request->cursus_id,
                'level_id' => $request->level_id,
                'size' => $request->size,
                'gender' => $request->gender,
                'type' => $request->type ?? $classroom->type,
                'years' => $request->years ?? $classroom->years,
                'telegram_link' => $request->telegram_link
            ]);

            if ($request->has('schedules') && is_array($request->schedules)) {
                $existingScheduleIds = $classroom->schedules->pluck('id')->toArray();
                $updatedScheduleIds = [];

                foreach ($request->schedules as $scheduleData) {
                    if (isset($scheduleData['delete']) && $scheduleData['delete']) {
                        if (isset($scheduleData['id'])) {
                            ClassSchedule::where('id', $scheduleData['id'])
                                ->where('classroom_id', $classroom->id)
                                ->delete();
                        }
                        continue;
                    }

                    if (isset($scheduleData['id']) && $scheduleData['id']) {
                        $schedule = ClassSchedule::where('id', $scheduleData['id'])
                            ->where('classroom_id', $classroom->id)
                            ->first();

                        if ($schedule) {
                            $schedule->update([
                                'day' => $scheduleData['day'],
                                'start_time' => $scheduleData['start_time'],
                                'end_time' => $scheduleData['end_time'],
                                'teacher_name' => $scheduleData['teacher_name'] ?? null,
                                'teacher_id' => $scheduleData['teacher_id'] ?? null,
                            ]);
                            $updatedScheduleIds[] = $schedule->id;
                            $classroom->update([
                                'updated_by' => auth()->id(),
                            ]);
                        }
                    } else {
                        $newSchedule = $classroom->schedules()->create([
                            'day' => $scheduleData['day'],
                            'start_time' => $scheduleData['start_time'],
                            'end_time' => $scheduleData['end_time'],
                            'teacher_name' => $scheduleData['teacher_name'] ?? null,
                            'teacher_id' => $scheduleData['teacher_id'] ?? null,
                        ]);
                        $updatedScheduleIds[] = $newSchedule->id;
                    }
                }

                $schedulesToDelete = array_diff($existingScheduleIds, $updatedScheduleIds);
                if (!empty($schedulesToDelete)) {
                    ClassSchedule::whereIn('id', $schedulesToDelete)
                        ->where('classroom_id', $classroom->id)
                        ->delete();
                }
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
                'error' => 'Une erreur est survenue'
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
                'error' => 'Une erreur est survenue'
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
                'error' => 'Une erreur est survenue'
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
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function getAdminClassrooms(Request $request)
    {
        $classrooms = Classroom::with([
            'cursus',
            'level',
            'activeStudents.infos',
            'activeStudents' => function($query) {
                $query->select('users.id', 'users.first_name', 'users.last_name', 'users.email');
            }
        ])
            ->orderBy('cursus_id')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get();

        $decidedCounts = StudentYearOutcome::query()
            ->where('school_year_id', currentSchoolYearId())
            ->whereIn('classroom_id', $classrooms->pluck('id'))
            ->select('classroom_id', DB::raw('count(*) as c'))
            ->groupBy('classroom_id')
            ->pluck('c', 'classroom_id');

        return response()->json([
            'status' => 'success',
            'data' => $classrooms->map(function ($classroom) use ($decidedCounts) {
                return [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'cursus' => $classroom->cursus?->name ?? 'N/A',
                    'cursus_id' => $classroom->cursus_id,
                    'level' => $classroom->level?->name ?? 'N/A',
                    'level_id' => $classroom->level_id,
                    'gender' => $classroom->gender,
                    'size' => $classroom->size,
                    'student_count' => $classroom->student_count,
                    'available_spots' => $classroom->available_spots,
                    'decided_count' => (int) ($decidedCounts[$classroom->id] ?? 0),
                    'students' => $classroom->activeStudents->map(function ($student) {
                        return [
                            'id' => $student->id,
                            'first_name' => $student->first_name,
                            'last_name' => $student->last_name,
                            'email' => $student->email,
                            'full_name' => $student->first_name . ' ' . $student->last_name,
                            'family_id' => $student->pivot->family_id,
                        ];
                    })
                ];
            })
        ]);
    }

    public function removeStudentFromClass(Request $request, $classroomId, $studentId)
    {
        try {
            $classroom = Classroom::findOrFail($classroomId);

            $enrollment = StudentClassroom::where('classroom_id', $classroomId)
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'L\'élève n\'est pas inscrit dans cette classe'
                ], 404);
            }

            $enrollment->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'L\'élève a été retiré de la classe avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'élève de la classe'
            ], 500);
        }
    }

    public function adminSuivi(Classroom $classroom)
    {
        if ($classroom->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $classroom->load('cursus:id,name', 'level:id,name', 'schedules.teacher:id,first_name,last_name');
        $yearId = currentSchoolYearId();

        $outcomes = StudentYearOutcome::query()
            ->where('classroom_id', $classroom->id)
            ->where('school_year_id', $yearId)
            ->get()
            ->keyBy('student_id');

        $attendances = Attendance::query()
            ->where('classroom_id', $classroom->id)
            ->get();

        $dates = $attendances
            ->map(fn ($a) => $a->date->toDateString())
            ->unique()
            ->sort()
            ->values();

        $attByStudent = $attendances->groupBy('student_id');

        $students = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->with('student:id,first_name,last_name')
            ->get()
            ->sortBy(fn ($sc) => mb_strtolower(trim(($sc->student?->last_name ?? '') . ' ' . ($sc->student?->first_name ?? ''))))
            ->values()
            ->map(function (StudentClassroom $sc) use ($outcomes, $attByStudent) {
                $o = $outcomes->get($sc->student_id);
                $att = [];
                foreach (($attByStudent->get($sc->student_id) ?? collect()) as $a) {
                    $att[$a->date->toDateString()] = ['status' => $a->status, 'justification' => $a->justification];
                }
                return [
                    'student_id' => $sc->student_id,
                    'first_name' => $sc->student?->first_name,
                    'last_name' => $sc->student?->last_name,
                    'outcome' => $o?->outcome,
                    'commentaire' => $o?->commentaire,
                    'attendance' => $att,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'classroom' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'gender' => $classroom->gender,
                    'cursus' => $classroom->cursus?->name,
                    'level' => $classroom->level?->name,
                    'schedules' => $classroom->schedules->map(fn ($s) => [
                        'day' => $s->day,
                        'start_time' => substr($s->start_time, 0, 5),
                        'end_time' => substr($s->end_time, 0, 5),
                        'teacher' => $s->teacher ? trim($s->teacher->first_name . ' ' . $s->teacher->last_name) : ($s->teacher_name ?: null),
                    ])->values(),
                ],
                'dates' => $dates,
                'students' => $students,
            ],
        ]);
    }

    public function adminOutcomesOverview()
    {
        $schoolId = currentSchoolId();
        $yearId = currentSchoolYearId();

        $classrooms = Classroom::query()
            ->where('school_id', $schoolId)
            ->with('cursus:id,name', 'level:id,name,order')
            ->get()
            ->keyBy('id');

        $outcomes = StudentYearOutcome::query()
            ->where('school_year_id', $yearId)
            ->get()
            ->keyBy(fn ($o) => $o->student_id . '-' . $o->classroom_id);

        $items = StudentClassroom::query()
            ->whereIn('classroom_id', $classrooms->keys())
            ->where('status', 'active')
            ->with('student:id,first_name,last_name')
            ->get()
            ->map(function (StudentClassroom $sc) use ($classrooms, $outcomes) {
                $c = $classrooms->get($sc->classroom_id);
                $o = $outcomes->get($sc->student_id . '-' . $sc->classroom_id);
                return [
                    'student_id' => $sc->student_id,
                    'first_name' => $sc->student?->first_name,
                    'last_name' => $sc->student?->last_name,
                    'classroom_id' => $sc->classroom_id,
                    'classroom_name' => $c?->name,
                    'cursus' => $c?->cursus?->name,
                    'cursus_id' => $c?->cursus_id,
                    'level' => $c?->level?->name,
                    'outcome' => $o?->outcome,
                    'commentaire' => $o?->commentaire,
                ];
            })
            ->sortBy(fn ($i) => mb_strtolower(trim(($i['last_name'] ?? '') . ' ' . ($i['first_name'] ?? ''))))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ['items' => $items],
        ]);
    }
}
