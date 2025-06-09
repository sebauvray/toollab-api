<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassroomRequest;
use App\Http\Requests\UpdateClassroomRequest;
use App\Models\Classroom;
use App\Traits\PaginationTrait;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    use PaginationTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Classroom::query()
            ->with(['cursus', 'level', 'school'])
            ->withCount(['userRoles as student_count' => function ($query) {
                $query->whereHas('role', function ($q) {
                    $q->where('slug', 'student');
                });
            }]);

        if ($request->has('cursus_id')) {
            $query->where('cursus_id', $request->cursus_id);
        }

        if ($request->has('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        $paginatedData = $this->paginateQuery($query, $request);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $paginatedData['items'],
                'pagination' => $paginatedData['pagination']
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClassroomRequest $request)
    {
        $data = $request->validated();
        try {
            $classroom = Classroom::create($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Classe créée avec succès',
                'data' => [
                    'classroom' => $classroom->load(['cursus', 'level'])
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la classe',
                'error' => $e->getMessage(),
                'trace' => ENV('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Classroom $classroom)
    {
        $classroom->load(['cursus', 'level', 'school']);
        $classroom->loadCount(['userRoles as student_count' => function ($query) {
            $query->whereHas('role', function ($q) {
                $q->where('slug', 'student');
            });
        }]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'classroom' => $classroom
            ]
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClassroomRequest $request, Classroom $classroom)
    {
        $data = $request->validated();

        try {
            $classroom->update($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Classe mise à jour avec succès',
                'data' => [
                    'classroom' => $classroom->fresh()->load(['cursus', 'level'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Classroom $classroom)
    {
        try {
            $studentCount = $classroom->userRoles()
                ->whereHas('role', function ($query) {
                    $query->where('slug', 'student');
                })
                ->count();

            if ($studentCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette classe car elle contient des élèves'
                ], 422);
            }

            $classroom->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Classe supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression de la classe',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
