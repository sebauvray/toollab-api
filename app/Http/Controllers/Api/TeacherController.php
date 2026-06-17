<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherController extends Controller
{
    private function ensureTeacher(int $schoolId): bool
    {
        $teacherRoleId = Role::where('slug', 'teacher')->value('id');
        if (!$teacherRoleId) return false;

        return UserRole::query()
            ->where('user_id', auth()->id())
            ->where('role_id', $teacherRoleId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->exists();
    }

    private function teacherTeachesClassroom(int $classroomId): bool
    {
        return DB::table('class_schedules')
            ->where('classroom_id', $classroomId)
            ->where('teacher_id', auth()->id())
            ->exists();
    }

    private function guardClassroom(Classroom $classroom)
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }
        if (!$this->ensureTeacher($schoolId)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        if ($classroom->school_id !== $schoolId) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        if (!$this->teacherTeachesClassroom($classroom->id)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        return null;
    }

    public function myClassrooms()
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        if (!$this->ensureTeacher($schoolId)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $classrooms = Classroom::query()
            ->where('school_id', $schoolId)
            ->whereHas('schedules', fn ($q) => $q->where('teacher_id', auth()->id()))
            ->with(['cursus:id,name', 'level:id,name', 'schedules' => function ($q) {
                $q->where('teacher_id', auth()->id());
            }])
            ->orderBy('name')
            ->get()
            ->map(function (Classroom $c) {
                $mainTeacherId = $c->main_teacher_id
                    ?? $c->schedules()->whereNotNull('teacher_id')->orderBy('id')->value('teacher_id');
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'gender' => $c->gender,
                    'size' => $c->size,
                    'is_main_teacher' => $mainTeacherId === auth()->id(),
                    'student_count' => $c->student_count,
                    'cursus' => $c->cursus?->name,
                    'level' => $c->level?->name,
                    'schedules' => $c->schedules->map(fn ($s) => [
                        'day' => $s->day,
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                    ]),
                ];
            });

        return response()->json(['status' => 'success', 'data' => $classrooms]);
    }

    public function classroomStudents(Classroom $classroom)
    {
        if ($resp = $this->guardClassroom($classroom)) {
            return $resp;
        }

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;
        $classroom->loadMissing('cursus:id,name,progression');

        $outcomes = $yearId
            ? StudentYearOutcome::query()
                ->where('classroom_id', $classroom->id)
                ->where('school_year_id', $yearId)
                ->get()
                ->keyBy('student_id')
            : collect();

        $students = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->with('student:id,first_name,last_name')
            ->get()
            ->sortBy(fn (StudentClassroom $sc) => mb_strtolower(trim(($sc->student?->last_name ?? '') . ' ' . ($sc->student?->first_name ?? ''))))
            ->values()
            ->map(function (StudentClassroom $sc) use ($outcomes) {
                $outcome = $outcomes->get($sc->student_id);
                return [
                    'student_id' => $sc->student_id,
                    'first_name' => $sc->student?->first_name,
                    'last_name' => $sc->student?->last_name,
                    'outcome' => $outcome?->outcome,
                    'commentaire' => $outcome?->commentaire,
                    'decided_at' => $outcome?->decided_at,
                ];
            });

        $schedules = DB::table('class_schedules')
            ->where('classroom_id', $classroom->id)
            ->where('teacher_id', auth()->id())
            ->orderByRaw("FIELD(day, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche')")
            ->orderBy('start_time')
            ->get(['id', 'day', 'start_time', 'end_time'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'day' => $s->day,
                'start_time' => substr($s->start_time, 0, 5),
                'end_time' => substr($s->end_time, 0, 5),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'classroom' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'gender' => $classroom->gender,
                    'cursus' => $classroom->cursus ? [
                        'id' => $classroom->cursus->id,
                        'name' => $classroom->cursus->name,
                        'progression' => $classroom->cursus->progression,
                    ] : null,
                ],
                'outcomes_open' => $year ? (bool) $year->outcomes_open : false,
                'is_main_teacher' => $classroom->effectiveMainTeacherId() === auth()->id(),
                'year_closed' => $year ? !$year->isOpen() : true,
                'schedules' => $schedules,
                'students' => $students,
            ],
        ]);
    }

    public function saveOutcomes(Request $request, Classroom $classroom)
    {
        if ($resp = $this->guardClassroom($classroom)) {
            return $resp;
        }

        if ($classroom->effectiveMainTeacherId() !== auth()->id()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

        if (!$year || !$year->isOpen()) {
            return response()->json(['message' => 'Année clôturée'], 409);
        }

        if (!$year->outcomes_open) {
            return response()->json(['message' => 'Saisie des décisions non activée'], 409);
        }

        $classroom->loadMissing('cursus:id,name,progression');

        $request->validate([
            'decisions' => 'present|array',
            'decisions.*.student_id' => 'required|integer|exists:users,id',
            'decisions.*.outcome' => 'required|in:passage,redoublement,exclusion,fin_cursus',
            'decisions.*.commentaire' => 'nullable|string|max:2000',
        ]);

        if ($classroom->cursus?->progression === 'continu') {
            $hasLevelDecision = collect($request->input('decisions'))
                ->contains(fn ($decision) => in_array($decision['outcome'] ?? null, ['passage', 'redoublement'], true));

            if ($hasLevelDecision) {
                return response()->json([
                    'message' => 'Cette classe appartient à un cursus continu : passage et redoublement ne peuvent pas être saisis.',
                ], 422);
            }
        }

        $enrolledIds = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->pluck('student_id')
            ->all();

        $keptIds = [];

        DB::transaction(function () use ($request, $classroom, $year, $enrolledIds, &$keptIds) {
            foreach ($request->input('decisions') as $d) {
                if (!in_array((int) $d['student_id'], $enrolledIds, true)) continue;

                StudentYearOutcome::updateOrCreate(
                    [
                        'student_id' => $d['student_id'],
                        'school_year_id' => $year->id,
                        'classroom_id' => $classroom->id,
                    ],
                    [
                        'outcome' => $d['outcome'],
                        'commentaire' => $d['commentaire'] ?? null,
                        'decided_by' => auth()->id(),
                        'decided_at' => now(),
                    ]
                );
                $keptIds[] = (int) $d['student_id'];
            }

            StudentYearOutcome::where('classroom_id', $classroom->id)
                ->where('school_year_id', $year->id)
                ->whereNotIn('student_id', $keptIds ?: [0])
                ->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Décisions enregistrées',
        ]);
    }

    public function classroomAttendance(Request $request, Classroom $classroom)
    {
        if ($resp = $this->guardClassroom($classroom)) {
            return $resp;
        }

        $request->validate(['date' => 'nullable|date']);
        $date = $request->input('date') ?: now()->toDateString();

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

        $records = Attendance::query()
            ->where('classroom_id', $classroom->id)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        $students = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->with('student:id,first_name,last_name')
            ->get()
            ->sortBy(fn (StudentClassroom $sc) => mb_strtolower(trim(($sc->student?->last_name ?? '') . ' ' . ($sc->student?->first_name ?? ''))))
            ->values()
            ->map(function (StudentClassroom $sc) use ($records) {
                $a = $records->get($sc->student_id);
                return [
                    'student_id' => $sc->student_id,
                    'first_name' => $sc->student?->first_name,
                    'last_name' => $sc->student?->last_name,
                    'status' => $a?->status,
                    'justification' => $a?->justification,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'date' => $date,
                'year_closed' => $year ? !$year->isOpen() : true,
                'students' => $students,
            ],
        ]);
    }

    public function saveAttendance(Request $request, Classroom $classroom)
    {
        if ($resp = $this->guardClassroom($classroom)) {
            return $resp;
        }

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

        if (!$year || !$year->isOpen()) {
            return response()->json(['message' => 'Année clôturée'], 409);
        }

        $request->validate([
            'date' => 'required|date',
            'records' => 'present|array',
            'records.*.student_id' => 'required|integer|exists:users,id',
            'records.*.status' => 'required|in:present,absent_justifie,absent_non_justifie',
            'records.*.justification' => 'nullable|string|max:2000',
        ]);

        $enrolledIds = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->pluck('student_id')
            ->all();

        $date = $request->input('date');
        $keptIds = [];

        DB::transaction(function () use ($request, $classroom, $date, $enrolledIds, &$keptIds) {
            foreach ($request->input('records') as $r) {
                if (!in_array((int) $r['student_id'], $enrolledIds, true)) {
                    continue;
                }

                Attendance::updateOrCreate(
                    [
                        'student_id' => $r['student_id'],
                        'classroom_id' => $classroom->id,
                        'date' => $date,
                    ],
                    [
                        'status' => $r['status'],
                        'justification' => $r['status'] === 'absent_justifie' ? ($r['justification'] ?? null) : null,
                    ]
                );
                $keptIds[] = (int) $r['student_id'];
            }

            Attendance::where('classroom_id', $classroom->id)
                ->whereDate('date', $date)
                ->whereNotIn('student_id', $keptIds ?: [0])
                ->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Émargement enregistré',
        ]);
    }

    public function classroomAttendanceMatrix(Classroom $classroom)
    {
        if ($resp = $this->guardClassroom($classroom)) {
            return $resp;
        }

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

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
            ->map(function (StudentClassroom $sc) use ($attByStudent) {
                $att = [];
                foreach (($attByStudent->get($sc->student_id) ?? collect()) as $a) {
                    $att[$a->date->toDateString()] = ['status' => $a->status, 'justification' => $a->justification];
                }
                return [
                    'student_id' => $sc->student_id,
                    'first_name' => $sc->student?->first_name,
                    'last_name' => $sc->student?->last_name,
                    'attendance' => $att,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'year_closed' => $year ? !$year->isOpen() : true,
                'dates' => $dates,
                'students' => $students,
            ],
        ]);
    }
}
