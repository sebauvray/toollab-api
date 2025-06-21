<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Paiement;
use App\Models\LignePaiement;
use Illuminate\Support\Facades\DB;

class PaiementService
{
    protected $tarifCalculator;

    public function __construct(TarifCalculatorService $tarifCalculator)
    {
        $this->tarifCalculator = $tarifCalculator;
    }

    public function getOrCreatePaiement(Family $family, int $userId): Paiement
    {
        return Paiement::firstOrCreate(
            ['family_id' => $family->id],
            ['created_by' => $userId]
        );
    }

    public function ajouterLignePaiement(Paiement $paiement, array $data): LignePaiement
    {
        $details = null;

        if ($data['type'] === 'cheque' && isset($data['cheque'])) {
            $details = $data['cheque'];
        } elseif ($data['type'] === 'exoneration' && isset($data['justification'])) {
            $details = ['justification' => $data['justification']];
        }

        return LignePaiement::create([
            'paiement_id' => $paiement->id,
            'type_paiement' => $data['type'],
            'montant' => round($data['montant'], 0, PHP_ROUND_HALF_UP),
            'details' => $details
        ]);
    }

    public function modifierLignePaiement(LignePaiement $ligne, array $data): LignePaiement
    {
        $updateData = ['montant' => round($data['montant'], 0, PHP_ROUND_HALF_UP)];

        if ($ligne->type_paiement === 'cheque' && isset($data['cheque'])) {
            $updateData['details'] = $data['cheque'];
        } elseif ($ligne->type_paiement === 'exoneration' && isset($data['justification'])) {
            $updateData['details'] = ['justification' => $data['justification']];
        }

        $ligne->update($updateData);
        return $ligne;
    }

    public function supprimerLignePaiement(LignePaiement $ligne): bool
    {
        return $ligne->delete();
    }

    public function getDetailsPaiement(Family $family): array
    {
        $paiement = Paiement::where('family_id', $family->id)
            ->with(['lignes'])
            ->first();

        $montantPaye = 0;
        $details = [
            'espece' => 0,
            'carte' => 0,
            'cheque' => 0,
            'exoneration' => 0,
            'cheques' => []
        ];

        if ($paiement) {
            foreach ($paiement->lignes as $ligne) {
                $montantPaye += $ligne->montant;
                $details[$ligne->type_paiement] += $ligne->montant;

                if ($ligne->type_paiement === 'cheque' && $ligne->details) {
                    $details['cheques'][] = $ligne->details;
                }
            }
        }

        $tarifs = $this->tarifCalculator->calculerTotalFamille($family);
        $montantTotal = $tarifs['total'];
        $resteAPayer = $montantTotal - $montantPaye;

        return [
            'paiement' => $paiement,
            'montant_total' => $montantTotal,
            'montant_paye' => $montantPaye,
            'reste_a_payer' => $resteAPayer,
            'details' => $details,
            'tarifs' => $tarifs
        ];
    }
}
