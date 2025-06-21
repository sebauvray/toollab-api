<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Cursus;
use App\Models\User;
use App\Models\StudentClassroom;
use Illuminate\Support\Collection;

class TarifCalculatorService
{
    public function calculerTotalFamille(Family $family, array $inscriptionsData = null): array
    {
        if ($inscriptionsData === null) {
            $inscriptionsData = $this->getInscriptionsActives($family);
        }

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

                if (!$cursus || !$cursus->tarif) {
                    continue;
                }

                $tarifBase = intval($cursus->tarif->prix);

                $nombreElevesCursus = $this->countStudentsInCursus($inscriptionsParCursus, $cursusId);
                $reductionFamiliale = $this->getReductionFamiliale($cursus, $nombreElevesCursus);

                $autresCursusEleve = $this->getAutresCursusEleve($inscriptionsData, $student->id, $cursusId);
                $reductionMultiCursus = $this->getReductionMultiCursus($cursus, $autresCursusEleve);

                $reduction = max($reductionFamiliale, $reductionMultiCursus);
                $tarifFinal = $tarifBase * (1 - $reduction / 100);

                $detailEleve['cursus'][] = [
                    'cursus_id' => $cursusId,
                    'cursus_name' => $cursus->name,
                    'tarif_base' => $tarifBase,
                    'reduction_familiale' => $reductionFamiliale,
                    'reduction_multi_cursus' => $reductionMultiCursus,
                    'reduction_appliquee' => $reduction,
                    'tarif_final' => round($tarifFinal, 0, PHP_ROUND_HALF_UP)
                ];

                $totalFamille += round($tarifFinal, 0, PHP_ROUND_HALF_UP);
            }

            $detailsParEleve[] = $detailEleve;
        }

        $responsable = $family->userRoles->first();
        $responsable_fullname = $responsable && $responsable->user ? $responsable->user->first_name . ' ' . $responsable->user->last_name : 'Sans responsable';

        return [
            'total' => $totalFamille,
            'total_famille' => $totalFamille,
            'details_par_eleve' => $detailsParEleve,
            'nombre_eleves' => count($inscriptionsData),
            'nom_famille' => $responsable_fullname,
            'id_famille' => $family->id,
        ];
    }

    private function getInscriptionsActives(Family $family): array
    {
        $inscriptions = StudentClassroom::where('family_id', $family->id)
            ->where('status', 'active')
            ->with(['student', 'classroom.cursus'])
            ->get()
            ->groupBy('student_id');

        $result = [];

        foreach ($inscriptions as $studentId => $studentInscriptions) {
            $classes = $studentInscriptions->map(function ($inscription) {
                return [
                    'classroom_id' => $inscription->classroom_id,
                    'cursus_id' => $inscription->classroom->cursus_id
                ];
            })->toArray();

            $result[] = [
                'student_id' => $studentId,
                'classes' => $classes
            ];
        }

        return $result;
    }

    private function groupInscriptionsByCursus(array $inscriptionsData): array
    {
        $result = [];

        foreach ($inscriptionsData as $inscription) {
            foreach ($inscription['classes'] as $class) {
                $cursusId = $class['cursus_id'];
                if (!isset($result[$cursusId])) {
                    $result[$cursusId] = [];
                }
                $result[$cursusId][] = $inscription['student_id'];
            }
        }

        return array_map(function ($students) {
            return array_unique($students);
        }, $result);
    }

    private function countStudentsInCursus(array $inscriptionsParCursus, int $cursusId): int
    {
        return isset($inscriptionsParCursus[$cursusId]) ? count($inscriptionsParCursus[$cursusId]) : 0;
    }

    private function getReductionFamiliale(Cursus $cursus, int $nombreEleves): float
    {
        if ($nombreEleves <= 1) {
            return 0;
        }

        $reduction = $cursus->reductionsFamiliales
            ->where('actif', true)
            ->where('nombre_eleves_min', '<=', $nombreEleves)
            ->sortByDesc('nombre_eleves_min')
            ->first();

        return $reduction ? floatval($reduction->pourcentage_reduction) : 0;
    }

    private function getAutresCursusEleve(array $inscriptionsData, int $studentId, int $cursusActuelId): array
    {
        $inscription = collect($inscriptionsData)->firstWhere('student_id', $studentId);

        if (!$inscription) {
            return [];
        }

        return collect($inscription['classes'])
            ->pluck('cursus_id')
            ->unique()
            ->reject(function ($cursusId) use ($cursusActuelId) {
                return $cursusId == $cursusActuelId;
            })
            ->values()
            ->toArray();
    }

    private function getReductionMultiCursus(Cursus $cursus, array $autresCursusIds): float
    {
        if (empty($autresCursusIds)) {
            return 0;
        }

        $reduction = $cursus->reductionsMultiCursusBeneficiaire
            ->where('actif', true)
            ->whereIn('cursus_requis_id', $autresCursusIds)
            ->sortByDesc('pourcentage_reduction')
            ->first();

        return $reduction ? floatval($reduction->pourcentage_reduction) : 0;
    }
}
