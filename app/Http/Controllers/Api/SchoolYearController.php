<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchoolYearController extends Controller
{
    public function index()
    {
        $schoolId = currentSchoolId();

        $years = SchoolYear::query()
            ->where('school_id', $schoolId)
            ->orderByDesc('is_active')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($y) => [
                'id' => $y->id,
                'label' => $y->label,
                'opened_at' => $y->opened_at,
                'closed_at' => $y->closed_at,
                'is_active' => $y->is_active,
                'is_read_only' => !$y->is_active || $y->closed_at !== null,
                'outcomes_open' => (bool) $y->outcomes_open,
            ]);

        return response()->json(['status' => 'success', 'data' => $years]);
    }

    public function store(Request $request)
    {
        $schoolId = currentSchoolId();

        $request->validate([
            'label' => 'required|string|max:64',
            'clone_from_year_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('school_years', 'id')->where('school_id', $schoolId),
            ],
            'clone_cursus_ids' => ['nullable', 'array'],
            'clone_cursus_ids.*' => ['integer'],
        ]);

        $exists = SchoolYear::query()
            ->where('school_id', $schoolId)
            ->where('label', $request->label)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une année avec ce libellé existe déjà',
            ], 422);
        }

        try {
            $year = DB::transaction(function () use ($request, $schoolId) {
                $sourceYearId = null;
                if ($request->filled('clone_from_year_id')) {
                    $source = SchoolYear::query()
                        ->where('id', $request->clone_from_year_id)
                        ->where('school_id', $schoolId)
                        ->first();
                    if (!$source) {
                        abort(response()->json([
                            'status' => 'error',
                            'message' => 'Année source introuvable',
                        ], 404));
                    }
                    $sourceYearId = $source->id;
                }

                SchoolYear::query()
                    ->where('school_id', $schoolId)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'closed_at' => now()]);

                $year = SchoolYear::create([
                    'label' => $request->label,
                    'opened_at' => now(),
                    'is_active' => true,
                ]);

                if ($sourceYearId !== null) {
                    $this->cloneTarification($sourceYearId, $year->id, $request->input('clone_cursus_ids'));
                }

                return $year;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Année scolaire créée',
                'data' => [
                    'id' => $year->id,
                    'label' => $year->label,
                    'is_active' => $year->is_active,
                    'opened_at' => $year->opened_at,
                ],
            ], 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException | \Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('SchoolYear.store failed', ['exception' => $e]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    public function close(SchoolYear $schoolYear)
    {
        if ($schoolYear->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        if (!$schoolYear->is_active || $schoolYear->closed_at !== null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Année déjà clôturée',
            ], 409);
        }

        $schoolYear->is_active = false;
        $schoolYear->closed_at = now();
        $schoolYear->outcomes_open = false;
        $schoolYear->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Année clôturée',
            'data' => [
                'id' => $schoolYear->id,
                'closed_at' => $schoolYear->closed_at,
                'is_active' => $schoolYear->is_active,
            ],
        ]);
    }

    public function toggleOutcomes(Request $request, SchoolYear $schoolYear)
    {
        if ($schoolYear->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if (!$schoolYear->is_active || $schoolYear->closed_at !== null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Année clôturée, module non modifiable',
            ], 409);
        }

        $request->validate(['open' => 'required|boolean']);

        $schoolYear->outcomes_open = (bool) $request->input('open');
        $schoolYear->save();

        return response()->json([
            'status' => 'success',
            'message' => $schoolYear->outcomes_open
                ? 'Saisie des décisions activée'
                : 'Saisie des décisions désactivée',
            'data' => [
                'id' => $schoolYear->id,
                'outcomes_open' => $schoolYear->outcomes_open,
            ],
        ]);
    }

    /**
     * Liste les classes d'une année donnée (sans élèves), groupées par
     * cursus + niveau. Inclut un flag indiquant si une classe identique
     * (même nom, cursus, niveau, type, gender) existe déjà dans l'année
     * active — pour éviter les doublons à la reconduction.
     */
    public function classroomsForReconduction(SchoolYear $schoolYear)
    {
        if ($schoolYear->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $activeYear = SchoolYear::query()
            ->where('school_id', currentSchoolId())
            ->where('is_active', true)
            ->first();

        $classrooms = Classroom::query()
            ->withoutGlobalScope(\App\Models\Scopes\BelongsToSchoolYearScope::class)
            ->where('school_year_id', $schoolYear->id)
            ->with(['cursus:id,name', 'level:id,name,order'])
            ->orderBy('cursus_id')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get();

        // Précharger les classes de l'année active pour calculer reconducted
        $activeKeys = collect();
        if ($activeYear) {
            $activeKeys = Classroom::query()
                ->where('school_year_id', $activeYear->id)
                ->get(['name', 'cursus_id', 'level_id', 'type', 'gender'])
                ->map(fn ($c) => $c->name . '|' . $c->cursus_id . '|' . $c->level_id . '|' . $c->type . '|' . $c->gender)
                ->flip();
        }

        $rows = $classrooms->map(function ($c) use ($activeKeys) {
            $key = $c->name . '|' . $c->cursus_id . '|' . $c->level_id . '|' . $c->type . '|' . $c->gender;
            return [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'gender' => $c->gender,
                'size' => (int) $c->size,
                'cursus' => $c->cursus ? ['id' => $c->cursus->id, 'name' => $c->cursus->name] : null,
                'level' => $c->level ? ['id' => $c->level->id, 'name' => $c->level->name, 'order' => $c->level->order] : null,
                'already_in_active_year' => $activeKeys->has($key),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'source_year' => [
                    'id' => $schoolYear->id,
                    'label' => $schoolYear->label,
                    'is_active' => $schoolYear->is_active,
                ],
                'active_year' => $activeYear ? [
                    'id' => $activeYear->id,
                    'label' => $activeYear->label,
                ] : null,
                'classrooms' => $rows,
            ],
        ]);
    }

    public function reconductClassroom(Request $request, Classroom $classroom)
    {
        if ($classroom->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $request->validate([
            'size' => 'sometimes|integer|min:1|max:999',
            'name' => 'sometimes|string|max:255',
        ]);

        $activeYear = SchoolYear::query()
            ->where('school_id', currentSchoolId())
            ->where('is_active', true)
            ->first();

        if (!$activeYear) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucune année active vers laquelle reconduire',
            ], 409);
        }

        if ($classroom->school_year_id === $activeYear->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'La classe appartient déjà à l\'année active',
            ], 422);
        }

        $size = $request->filled('size') ? (int) $request->input('size') : (int) $classroom->size;
        $name = $request->filled('name') ? trim($request->input('name')) : $classroom->name;

        $clone = DB::transaction(function () use ($classroom, $activeYear, $size, $name) {
            $new = new Classroom([
                'name' => $name,
                'years' => $classroom->years,
                'type' => $classroom->type,
                'size' => $size,
                'cursus_id' => $classroom->cursus_id,
                'level_id' => $classroom->level_id,
                'gender' => $classroom->gender,
                'telegram_link' => $classroom->telegram_link,
            ]);
            $new->school_id = $classroom->school_id;
            $new->school_year_id = $activeYear->id;
            $new->save();

            foreach ($classroom->schedules as $schedule) {
                ClassSchedule::create([
                    'classroom_id' => $new->id,
                    'day' => $schedule->day,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'teacher_name' => $schedule->teacher_name,
                ]);
            }

            return $new->fresh(['schedules']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Classe reconduite',
            'data' => [
                'id' => $clone->id,
                'name' => $clone->name,
                'school_year_id' => $clone->school_year_id,
            ],
        ], 201);
    }

    /**
     * Clone tarifs + réductions de l'année source vers la cible.
     * Les cursus eux-mêmes sont permanents donc inchangés.
     */
    private function cloneTarification(int $sourceYearId, int $targetYearId, ?array $cursusIds = null): void
    {
        $now = now();
        $userId = auth()->id();

        $tables = [
            'tarifs' => ['filter' => 'cursus_id', 'cols' => ['cursus_id', 'prix', 'actif']],
            'reduction_familiales' => ['filter' => 'cursus_id', 'cols' => ['cursus_id', 'nombre_eleves_min', 'pourcentage_reduction', 'actif']],
            'reduction_multi_cursuses' => ['filter' => 'cursus_beneficiaire_id', 'cols' => ['cursus_beneficiaire_id', 'cursus_requis_id', 'pourcentage_reduction', 'actif']],
        ];

        foreach ($tables as $table => $def) {
            $query = DB::table($table)->where('school_year_id', $sourceYearId);
            if ($cursusIds !== null) {
                $query->whereIn($def['filter'], $cursusIds);
            }
            $query->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $def, $targetYearId, $now, $userId) {
                    $payload = [];
                    foreach ($rows as $row) {
                        $base = ['school_year_id' => $targetYearId, 'created_by' => $userId, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now];
                        foreach ($def['cols'] as $col) {
                            $base[$col] = $row->$col;
                        }
                        $payload[] = $base;
                    }
                    if ($payload) {
                        DB::table($table)->insert($payload);
                    }
                });
        }
    }
}
