<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursusRequest;
use App\Http\Requests\UpdateCursusRequest;
use App\Models\Cursus;
use App\Models\CursusLevel;
use App\Traits\PaginationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CursusController extends Controller
{
    use PaginationTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Cursus::with('levels')
            ->withCount('classrooms as class_count');

        if ($request->has('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        $paginatedData = $this->paginateQuery($query, $request);

        $formattedItems = collect($paginatedData['items'])->map(function ($cursus) {
            return [
                'id' => $cursus->id,
                'name' => $cursus->name,
                'progression' => $cursus->progression,
                'type' => $cursus->progression === 'levels' ? 'Par niveaux' : 'Continu',
                'classCount' => $cursus->class_count,
                'levels' => $cursus->levels->map(function ($level) {
                    return [
                        'id' => $level->id,
                        'name' => $level->name,
                        'order' => $level->order
                    ];
                })
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $formattedItems,
                'pagination' => $paginatedData['pagination']
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCursusRequest $request)
    {
        $validatedData = $request->validated();

        DB::beginTransaction();

        try {
            $cursus = Cursus::create([
                'name' => $validatedData['name'],
                'progression' => $validatedData['progression'],
                'school_id' => $validatedData['school_id'],
            ]);

            foreach ($validatedData['levels'] as $index => $levelData) {
                $cursus->levels()->create([
                    'name' => $levelData['name'],
                    'order' => $index,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Cursus créé avec succès',
                'data' => [
                    'cursus' => $cursus->load('levels')
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création du cursus',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Cursus $cursus)
    {
        $cursus->load('levels');

        return response()->json([
            'status' => 'success',
            'data' => [
                'cursus' => [
                    'id' => $cursus->id,
                    'name' => $cursus->name,
                    'progression' => $cursus->progression,
                    'type' => $cursus->progression === 'levels' ? 'Par niveaux' : 'Continu',
                    'school_id' => $cursus->school_id,
                    'levels' => $cursus->levels->map(function ($level) {
                        return [
                            'id' => $level->id,
                            'name' => $level->name,
                            'order' => $level->order
                        ];
                    })
                ]
            ]
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCursusRequest $request, Cursus $cursus)
    {
        $validatedData = $request->validated();

        DB::beginTransaction();

        try {
            // Update cursus
            if (isset($validatedData['name'])) {
                $cursus->name = $validatedData['name'];
            }

            if (isset($validatedData['progression'])) {
                $cursus->progression = $validatedData['progression'];
            }

            $cursus->save();

            // Update levels if provided
            if (isset($validatedData['levels'])) {
                $existingLevelIds = [];

                foreach ($validatedData['levels'] as $index => $levelData) {
                    if (isset($levelData['id'])) {
                        // Update existing level
                        $level = CursusLevel::findOrFail($levelData['id']);
                        $level->name = $levelData['name'];
                        $level->order = $index;
                        $level->save();

                        $existingLevelIds[] = $level->id;
                    } else {
                        // Create new level
                        $level = $cursus->levels()->create([
                            'name' => $levelData['name'],
                            'order' => $index,
                        ]);

                        $existingLevelIds[] = $level->id;
                    }
                }

                // Delete levels that are no longer in the list
                $cursus->levels()->whereNotIn('id', $existingLevelIds)->delete();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Cursus mis à jour avec succès',
                'data' => [
                    'cursus' => $cursus->fresh()->load('levels')
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du cursus',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cursus $cursus)
    {
        DB::beginTransaction();

        try {
            // On récupère les classes associées pour pouvoir les supprimer
            $classrooms = $cursus->classrooms()->get();

            foreach ($classrooms as $classroom) {
                // Supprime toutes les relations user_roles (donc les élèves dans cette classe)
                $classroom->userRoles()->delete();

                // Supprime la classe
                $classroom->delete();
            }

            // Supprime tous les niveaux de ce cursus
            $cursus->levels()->delete();

            // Enfin, supprime le cursus
            $cursus->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Cursus et toutes ses classes associées supprimés avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression du cursus',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
