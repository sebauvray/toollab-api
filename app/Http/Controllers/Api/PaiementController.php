<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Paiement;
use App\Models\LignePaiement;
use App\Services\TarifCalculatorService;
use App\Services\PaiementService;
use Illuminate\Http\Request;

class PaiementController extends Controller
{
    protected $tarifCalculator;
    protected $paiementService;

    public function __construct(TarifCalculatorService $tarifCalculator, PaiementService $paiementService)
    {
        $this->tarifCalculator = $tarifCalculator;
        $this->paiementService = $paiementService;
    }

    public function show(Request $request, Family $family)
    {
        try {
            $details = $this->paiementService->getDetailsPaiement($family);

            return response()->json([
                'status' => 'success',
                'data' => $details
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du chargement des paiements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function ajouterLigne(Request $request, Family $family)
    {
        $request->validate([
            'type' => 'required|in:espece,carte,cheque,exoneration',
            'montant' => 'required|numeric|min:0.01',
            'cheque' => 'required_if:type,cheque|array',
            'cheque.banque' => 'required_if:type,cheque|string',
            'cheque.numero' => 'required_if:type,cheque|string',
            'cheque.nom_emetteur' => 'required_if:type,cheque|string',
            'justification' => 'required_if:type,exoneration|string'
        ]);

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $nouveauMontantPaye = $details['montant_paye'] + $request->montant;

            if ($nouveauMontantPaye > $details['montant_total']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le montant total payé ne peut pas dépasser le montant dû'
                ], 422);
            }

            $paiement = $this->paiementService->getOrCreatePaiement($family, $request->user()->id);
            $ligne = $this->paiementService->ajouterLignePaiement($paiement, $request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement ajouté avec succès',
                'data' => [
                    'ligne' => $ligne,
                    'nouveau_total' => $nouveauMontantPaye,
                    'reste_a_payer' => $details['montant_total'] - $nouveauMontantPaye
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout du paiement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function modifierLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée'
            ], 404);
        }

        $typePaiement = $ligne->type_paiement;

        $rules = [
            'montant' => 'required|numeric|min:0.01',
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
            $nouveauMontantPaye = $details['montant_paye'] - $ligne->montant + $request->montant;

            if ($nouveauMontantPaye > $details['montant_total']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le montant total payé ne peut pas dépasser le montant dû'
                ], 422);
            }

            $ligne = $this->paiementService->modifierLignePaiement($ligne, $request->all());

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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function supprimerLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée'
            ], 404);
        }

        try {
            $montantSupprime = $ligne->montant;
            $this->paiementService->supprimerLignePaiement($ligne);

            $details = $this->paiementService->getDetailsPaiement($family);

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
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
