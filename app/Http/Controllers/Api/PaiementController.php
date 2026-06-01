<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckPaymentCompletionJob;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Services\PaiementService;
use Illuminate\Http\Request;

class PaiementController extends Controller
{
    protected $paiementService;

    public function __construct(PaiementService $paiementService)
    {
        $this->paiementService = $paiementService;
    }

    public function show(Family $family)
    {
        if (!FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        try {
            $details = $this->paiementService->getDetailsPaiement($family);

            return response()->json([
                'status' => 'success',
                'data' => $details
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des détails de paiement',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function ajouterLigne(Request $request, Family $family)
    {
        if (!FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        $request->validate([
            'type' => 'required|in:espece,carte,cheque,exoneration',
            'montant' => 'required|integer|min:1',
            'cheque' => 'required_if:type,cheque|array',
            'cheque.banque' => 'required_if:type,cheque|string',
            'cheque.numero' => 'required_if:type,cheque|string',
            'cheque.nom_emetteur' => 'required_if:type,cheque|string',
            'justification' => 'required_if:type,exoneration|string'
        ]);

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $family) {
                Family::lockForUpdate()->find($family->id);
                $details = $this->paiementService->getDetailsPaiement($family);
                $previousResteAPayer = $details['reste_a_payer'];
                $nouveauMontantPaye = $details['montant_paye'] + $request->montant;

                if ($nouveauMontantPaye > $details['montant_total']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le montant total payé ne peut pas dépasser le montant dû'
                    ], 422);
                }

                $paiement = $this->paiementService->getOrCreatePaiement($family, $request->user()->id);
                $ligne = $this->paiementService->ajouterLignePaiement($paiement, $request->all());

                CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Paiement ajouté avec succès',
                    'data' => [
                        'ligne' => $ligne,
                        'nouveau_total' => $nouveauMontantPaye,
                        'reste_a_payer' => $details['montant_total'] - $nouveauMontantPaye
                    ]
                ]);
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Paiement.ajouterLigne failed', ['exception' => $e]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout du paiement',
            ], 500);
        }
    }

    public function modifierLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if (!FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée'
            ], 404);
        }

        $typePaiement = $ligne->type_paiement;

        $rules = [
            'montant' => 'required|integer|min:1',
        ];

        if ($typePaiement === 'cheque') {
            $rules['cheque'] = 'required|array';
            $rules['cheque.banque'] = 'required|string';
            $rules['cheque.numero'] = 'required|string';
            $rules['cheque.nom_emetteur'] = 'required|string';
        } elseif ($typePaiement === 'exoneration') {
            $rules['justification'] = 'required|string';
        }

        $request->validate($rules);

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $previousResteAPayer = $details['reste_a_payer'];
            $nouveauMontantPaye = $details['montant_paye'] - $ligne->montant + $request->montant;

            if ($nouveauMontantPaye > $details['montant_total']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le montant total payé ne peut pas dépasser le montant dû'
                ], 422);
            }

            $ligne = $this->paiementService->modifierLignePaiement($ligne, $request->all());

            CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer);

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement modifié avec succès',
                'data' => [
                    'ligne' => $ligne,
                    'nouveau_total' => $nouveauMontantPaye,
                    'reste_a_payer' => $details['montant_total'] - $nouveauMontantPaye
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification du paiement',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function supprimerLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if (!FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée'
            ], 404);
        }

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $previousResteAPayer = $details['reste_a_payer'];

            $montantSupprime = $ligne->montant;
            $this->paiementService->supprimerLignePaiement($ligne);

            $details = $this->paiementService->getDetailsPaiement($family);

            CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer);

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement supprimé avec succès',
                'data' => [
                    'nouveau_total' => $details['montant_paye'],
                    'reste_a_payer' => $details['reste_a_payer']
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du paiement',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
