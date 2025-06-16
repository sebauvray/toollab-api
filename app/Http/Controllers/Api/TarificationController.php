<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cursus;
use App\Models\Tarif;
use App\Models\ReductionFamiliale;
use App\Models\ReductionMultiCursus;
use App\Services\TarifCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TarificationController extends Controller
{
    protected $tarifCalculator;

    public function __construct(TarifCalculatorService $tarifCalculator)
    {
        $this->tarifCalculator = $tarifCalculator;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé. Seuls les directeurs peuvent accéder à cette section.'
            ], 403);
        }

        $cursuses = Cursus::with(['tarif', 'reductionsFamiliales', 'reductionsMultiCursusBeneficiaire.cursusRequis'])
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'cursuses' => $cursuses->map(function ($cursus) {
                    return [
                        'id' => $cursus->id,
                        'name' => $cursus->name,
                        'tarif' => $cursus->tarif ? [
                            'id' => $cursus->tarif->id,
                            'prix' => $cursus->tarif->prix
                        ] : null,
                        'reductions_familiales' => $cursus->reductionsFamiliales->map(function ($reduction) {
                            return [
                                'id' => $reduction->id,
                                'nombre_eleves_min' => $reduction->nombre_eleves_min,
                                'pourcentage_reduction' => $reduction->pourcentage_reduction
                            ];
                        }),
                        'reductions_multi_cursus' => $cursus->reductionsMultiCursusBeneficiaire->map(function ($reduction) {
                            return [
                                'id' => $reduction->id,
                                'cursus_requis_id' => $reduction->cursus_requis_id,
                                'cursus_requis_nom' => $reduction->cursusRequis->name,
                                'pourcentage_reduction' => $reduction->pourcentage_reduction
                            ];
                        })
                    ];
                })
            ]
        ]);
    }

    public function updateTarif(Request $request, Cursus $cursus)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $request->validate([
            'prix' => 'required|numeric|min:0'
        ]);

        DB::transaction(function () use ($cursus, $request) {
            Tarif::updateOrCreate(
                ['cursus_id' => $cursus->id],
                ['prix' => $request->prix, 'actif' => true]
            );
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Tarif mis à jour avec succès'
        ]);
    }

    public function storeReductionFamiliale(Request $request, Cursus $cursus)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $request->validate([
            'nombre_eleves_min' => 'required|integer|min:2',
            'pourcentage_reduction' => 'required|numeric|min:0|max:100'
        ]);

        $reduction = ReductionFamiliale::create([
            'cursus_id' => $cursus->id,
            'nombre_eleves_min' => $request->nombre_eleves_min,
            'pourcentage_reduction' => $request->pourcentage_reduction,
            'actif' => true
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction familiale ajoutée avec succès',
            'data' => $reduction
        ]);
    }

    public function updateReductionFamiliale(Request $request, ReductionFamiliale $reduction)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $request->validate([
            'nombre_eleves_min' => 'required|integer|min:2',
            'pourcentage_reduction' => 'required|numeric|min:0|max:100'
        ]);

        $reduction->update($request->only(['nombre_eleves_min', 'pourcentage_reduction']));

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction familiale mise à jour avec succès'
        ]);
    }

    public function deleteReductionFamiliale(Request $request, ReductionFamiliale $reduction)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $reduction->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction familiale supprimée avec succès'
        ]);
    }

    public function storeReductionMultiCursus(Request $request, Cursus $cursus)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $request->validate([
            'cursus_requis_id' => 'required|exists:cursuses,id',
            'pourcentage_reduction' => 'required|numeric|min:0|max:100'
        ]);

        $existingReduction = ReductionMultiCursus::where('cursus_beneficiaire_id', $request->cursus_requis_id)
            ->where('cursus_requis_id', $cursus->id)
            ->first();

        if ($existingReduction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une dépendance circulaire a été détectée. Le cursus sélectionné a déjà une réduction qui dépend de ce cursus.'
            ], 422);
        }

        $reduction = ReductionMultiCursus::create([
            'cursus_beneficiaire_id' => $cursus->id,
            'cursus_requis_id' => $request->cursus_requis_id,
            'pourcentage_reduction' => $request->pourcentage_reduction,
            'actif' => true
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction multi-cursus ajoutée avec succès',
            'data' => $reduction
        ]);
    }

    public function updateReductionMultiCursus(Request $request, ReductionMultiCursus $reduction)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $request->validate([
            'pourcentage_reduction' => 'required|numeric|min:0|max:100'
        ]);

        $reduction->update(['pourcentage_reduction' => $request->pourcentage_reduction]);

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction multi-cursus mise à jour avec succès'
        ]);
    }

    public function deleteReductionMultiCursus(Request $request, ReductionMultiCursus $reduction)
    {
        $user = $request->user();
        $schoolId = $request->header('X-School-Id');

        if (!$this->isDirector($user, $schoolId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès non autorisé'
            ], 403);
        }

        $reduction->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Réduction multi-cursus supprimée avec succès'
        ]);
    }

    public function calculerTarifs(Request $request)
    {
        $request->validate([
            'family_id' => 'required|exists:families,id',
            'inscriptions' => 'required|array',
            'inscriptions.*.student_id' => 'required|exists:users,id',
            'inscriptions.*.classes' => 'required|array',
            'inscriptions.*.classes.*.cursus_id' => 'required|exists:cursuses,id'
        ]);

        $family = \App\Models\Family::find($request->family_id);
        $result = $this->tarifCalculator->calculerTotalFamille($family, $request->inscriptions);

        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }

    private function isDirector($user, $schoolId)
    {
        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.roleable_type', 'school')
            ->where('user_roles.roleable_id', $schoolId)
            ->where('roles.slug', 'director')
            ->exists();
    }
}
