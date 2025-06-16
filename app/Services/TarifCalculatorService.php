<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Cursus;
use App\Models\User;
use Illuminate\Support\Collection;

class TarifCalculatorService
{
    public function calculerTotalFamille(Family $family, array $inscriptionsData): array
    {
        $detailsParEleve = [];
        $totalFamille = 0;

        $inscriptionsParCursus = $this->groupInscriptionsByCursus($inscriptionsData);

        foreach ($inscriptionsData as $inscription) {
            $student = User::find($inscription['student_id']);
            $cursusIds = collect($inscription['classes'])->pluck('cursus_id')->unique();

            $detailEleve = [
                'student_id' => $student->id,
                'student_name' => $student->first_name . ' ' . $student->last_name,
                'cursus' => []
            ];

            foreach ($cursusIds as $cursusId) {
                $cursus = Cursus::with(['tarif', 'reductionsFamiliales', 'reductionsMultiCursusBeneficiaire'])->find($cursusId);

                if (!$cursus->tarif) continue;

                $prixBase = $cursus->tarif->prix;
                $reductions = [];

                $reductionFamiliale = $this->calculerReductionFamiliale($cursus, $inscriptionsParCursus[$cursusId]);
                if ($reductionFamiliale > 0) {
                    $reductions[] = [
                        'type' => 'familiale',
                        'pourcentage' => $reductionFamiliale,
                        'montant' => $prixBase * ($reductionFamiliale / 100)
                    ];
                }

                $reductionMultiCursus = $this->calculerReductionMultiCursus($cursus, $cursusIds);
                if ($reductionMultiCursus > 0) {
                    $reductions[] = [
                        'type' => 'multi_cursus',
                        'pourcentage' => $reductionMultiCursus,
                        'montant' => $prixBase * ($reductionMultiCursus / 100)
                    ];
                }

                $totalReductionPourcentage = collect($reductions)->sum('pourcentage');
                $totalReductionPourcentage = min($totalReductionPourcentage, 100);

                $prixFinal = $prixBase * (1 - $totalReductionPourcentage / 100);

                $detailEleve['cursus'][] = [
                    'cursus_id' => $cursus->id,
                    'cursus_name' => $cursus->name,
                    'prix_base' => $prixBase,
                    'reductions' => $reductions,
                    'prix_final' => $prixFinal
                ];

                $totalFamille += $prixFinal;
            }

            $detailsParEleve[] = $detailEleve;
        }

        return [
            'details_par_eleve' => $detailsParEleve,
            'total_famille' => $totalFamille
        ];
    }

    private function groupInscriptionsByCursus(array $inscriptionsData): array
    {
        $result = [];

        foreach ($inscriptionsData as $inscription) {
            $cursusIds = collect($inscription['classes'])->pluck('cursus_id')->unique();

            foreach ($cursusIds as $cursusId) {
                if (!isset($result[$cursusId])) {
                    $result[$cursusId] = [];
                }
                $result[$cursusId][] = $inscription['student_id'];
            }
        }

        return $result;
    }

    private function calculerReductionFamiliale(Cursus $cursus, array $studentsInscrits): float
    {
        $nombreEleves = count($studentsInscrits);

        $reductionApplicable = $cursus->reductionsFamiliales
            ->where('nombre_eleves_min', '<=', $nombreEleves)
            ->sortByDesc('nombre_eleves_min')
            ->first();

        return $reductionApplicable ? $reductionApplicable->pourcentage_reduction : 0;
    }

    private function calculerReductionMultiCursus(Cursus $cursus, Collection $cursusIdsEleve): float
    {
        $reductionMax = 0;

        foreach ($cursus->reductionsMultiCursusBeneficiaire as $reduction) {
            if ($cursusIdsEleve->contains($reduction->cursus_requis_id)) {
                $reductionMax = max($reductionMax, $reduction->pourcentage_reduction);
            }
        }

        return $reductionMax;
    }
}
