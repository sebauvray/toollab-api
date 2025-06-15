<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursusRequest;
use App\Http\Requests\UpdateCursusRequest;
use App\Models\Cursus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CursusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Cursus::with(['levels', 'classrooms']);

        if ($request->has('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        $perPage = $request->get('per_page', 10);
        $cursuses = $query->paginate($perPage);

        $items = $cursuses->items();
        $formattedItems = collect($items)->map(function ($cursus) {
            return [
                'id' => $cursus->id,
                'name' => $cursus->name,
                'progression' => $cursus->progression,
                'type' => $cursus->progression === 'levels' ? 'Par niveaux' : 'Continu',
                'classCount' => $cursus->classrooms->count(),
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
                'pagination' => [
                    'total' => $cursuses->total(),
                    'per_page' => $cursuses->perPage(),
                    'current_page' => $cursuses->currentPage(),
                    'total_pages' => $cursuses->lastPage()
                ]
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
                'levels_count' => $validatedData['progression'] === 'levels' ? $validatedData['levels_count'] : 0
            ]);

            if ($validatedData['progression'] === 'levels' && isset($validatedData['levels_count'])) {
                for ($i = 1; $i <= $validatedData['levels_count']; $i++) {
                    $cursus->levels()->create([
                        'name' => 'Niveau ' . $i,
                        'order' => $i
                    ]);
                }
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
        DB::beginTransaction();

        try {
            $cursus->update($request->validated());

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Cursus mis à jour avec succès',
                'data' => [
                    'cursus' => $cursus->load('levels')
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
            $classrooms = $cursus->classrooms()->get();

            foreach ($classrooms as $classroom) {
                $classroom->userRoles()->delete();
                $classroom->delete();
            }

            $cursus->levels()->delete();
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
