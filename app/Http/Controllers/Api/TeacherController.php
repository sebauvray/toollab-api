<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'gender' => $c->gender,
                    'size' => $c->size,
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

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

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

        return response()->json([
            'status' => 'success',
            'data' => [
                'classroom' => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'gender' => $classroom->gender,
                ],
                'outcomes_open' => $year ? (bool) $year->outcomes_open : false,
                'year_closed' => $year ? !$year->isOpen() : true,
                'students' => $students,
            ],
        ]);
    }

    public function saveOutcomes(Request $request, Classroom $classroom)
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

        $yearId = currentSchoolYearId();
        $year = $yearId ? SchoolYear::find($yearId) : null;

        if (!$year || !$year->isOpen()) {
            return response()->json(['message' => 'Année clôturée'], 409);
        }

        if (!$year->outcomes_open) {
            return response()->json(['message' => 'Saisie des décisions non activée'], 409);
        }

        $request->validate([
            'decisions' => 'required|array|min:1',
            'decisions.*.student_id' => 'required|integer|exists:users,id',
            'decisions.*.outcome' => 'required|in:passage,redoublement,exclusion,fin_cursus',
            'decisions.*.commentaire' => 'nullable|string|max:2000',
        ]);

        $enrolledIds = StudentClassroom::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', 'active')
            ->pluck('student_id')
            ->all();

        DB::transaction(function () use ($request, $classroom, $year, $enrolledIds) {
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
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Décisions enregistrées',
        ]);
    }
}
