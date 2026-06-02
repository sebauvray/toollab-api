<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $request->validate([
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);

        $query = ClassSchedule::query()
            ->whereHas('classroom', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->with(['classroom:id,name,gender,cursus_id,level_id', 'classroom.cursus:id,name', 'teacher:id,first_name,last_name']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->input('teacher_id'));
        }

        $schedules = $query->get()->map(function (ClassSchedule $s) {
            return [
                'id' => $s->id,
                'day' => $s->day,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'teacher_name' => $s->teacher_name,
                'teacher_id' => $s->teacher_id,
                'teacher' => $s->teacher ? [
                    'id' => $s->teacher->id,
                    'first_name' => $s->teacher->first_name,
                    'last_name' => $s->teacher->last_name,
                ] : null,
                'classroom' => $s->classroom ? [
                    'id' => $s->classroom->id,
                    'name' => $s->classroom->name,
                    'gender' => $s->classroom->gender,
                    'cursus_id' => $s->classroom->cursus_id,
                    'cursus_name' => $s->classroom->cursus?->name,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $schedules,
        ]);
    }

    public function mySchedules()
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $schedules = ClassSchedule::query()
            ->whereHas('classroom', fn ($q) => $q->where('school_id', $schoolId))
            ->where('teacher_id', auth()->id())
            ->with(['classroom:id,name,gender,cursus_id', 'classroom.cursus:id,name'])
            ->get()
            ->map(function (ClassSchedule $s) {
                return [
                    'id' => $s->id,
                    'day' => $s->day,
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'classroom' => $s->classroom ? [
                        'id' => $s->classroom->id,
                        'name' => $s->classroom->name,
                        'gender' => $s->classroom->gender,
                        'cursus_name' => $s->classroom->cursus?->name,
                    ] : null,
                ];
            });

        return response()->json(['status' => 'success', 'data' => $schedules]);
    }
}
