<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use App\Models\User;
use App\Models\UserInfo;
use Carbon\Carbon;

class ImportStudentInfosFromCsv extends Command
{
    protected $signature = 'import:student-infos-csv';
    protected $description = 'Import informations supplémentaires des étudiants à partir du fichier CSV';

    public function handle()
    {
        $csv = Reader::createFromPath(base_path('resources/data/EXP_ELEVE.csv'), 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();

        foreach ($records as $row) {
            $firstName = trim($row['PRENOM'] ?? '');
            $lastName = trim($row['NOM'] ?? '');
            $birthdate = null;

            if (!empty($row['DATE NAISS'])) {
                try {
                    $birthdate = Carbon::createFromFormat('d/m/Y', $row['DATE NAISS'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $this->warn("Date invalide pour {$lastName} {$firstName} : {$row['DATE NAISS']}");
                }
            }

            $query = User::where('first_name', $firstName)->where('last_name', $lastName);
            if ($birthdate) {
                $query->whereHas('infos', function ($q) use ($birthdate) {
                    $q->where('key', 'birthdate')->where('value', $birthdate);
                });
            }
            $student = $query->first();

            if (!$student) {
                $this->warn("Étudiant non trouvé: {$lastName} {$firstName} (naissance: {$birthdate})");
                continue;
            }

            $renvoi = false;
            $renvoi_motif = null;
            $renvoiCols = [
                'RENVOI mauvais résultats',
                'RENVOI absences',
                'RENVOI redoublement sans issue (pas de triplement)',
                'RENVOI comportement ou triche'
            ];

            foreach ($renvoiCols as $col) {
                if (isset($row[$col]) && strtoupper(trim($row[$col])) === 'VRAI') {
                    $renvoi = true;
                    $renvoi_motif = trim(str_replace('RENVOI', '', $col));
                    break;
                }
            }

            $infos = [
                'statut_scolaire' => strtolower($row['Primo / Doublant / Triplant']) ?? null,
                'abandon' => $this->bool($row['ABANDON ou DEPART'] ?? null),
                'renvoi' => $renvoi,
                'renvoi_motif' => $renvoi_motif,
                'passage' => $this->bool($row['PASSAGE ⚠️pas de passage sous condition'] ?? null),
                'redoublement' => $this->bool($row['REDOUBLEMENT ⚠️pas de triplement'] ?? null),
                'autre' => $this->bool($row['AUTRE (à préciser)'] ?? null),
                'commentaires' => $row['Commentaires'] ?? null,
                'classe_precedente' => $row['CLASSES 2024_2025'] ?? null,
            ];

            foreach ($infos as $key => $value) {
                if (!is_null($value)) {
                    UserInfo::updateOrCreate(
                        ['user_id' => $student->id, 'key' => $key],
                        ['value' => is_bool($value) ? ($value ? '1' : '0') : $value]
                    );
                }
            }
        }

        $this->info("Import terminé avec succès.");
    }

    private function bool($val): bool
    {
        return strtoupper(trim($val)) === 'VRAI';
    }
}
