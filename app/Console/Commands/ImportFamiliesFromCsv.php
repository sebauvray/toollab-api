<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\Role;
use App\Models\Family;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use League\Csv\Reader;

class ImportFamiliesFromCsv extends Command
{
    protected $signature = 'import:families-csv';
    protected $description = 'Import familles et étudiants à partir du fichier CSV';

    public function handle()
    {
        $csv = Reader::createFromPath(base_path('resources/data/EXP_ELEVE.csv'), 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();
        $records = iterator_to_array($records);

        $families = [];

        foreach ($records as $row) {
            $r1_ident = trim($row['R1_IDENT'] ?? '');
            if (empty($r1_ident)) {
                continue;
            }

            if (isset($families[$r1_ident])) {
                continue;
            }

            DB::beginTransaction();
            try {
                $existing = User::where('first_name', $row['R1_PRENOM'] ?? '')
                    ->where('last_name', $row['R1_NOM'] ?? '')
                    ->whereHas('infos', function ($query) use ($row) {
                        $query->where('key', 'birthdate')
                            ->where('value', $row['R1_DATE NAISSANCE'] ?? '');
                    })->first();

                $responsible = $existing ?? User::create([
                    'first_name' => $row['R1_PRENOM'] ?? 'Inconnu',
                    'last_name' => $row['R1_NOM'] ?? 'Inconnu',
                    'email' => (!empty($row['R1_EMAIL']) && !User::where('email', $row['R1_EMAIL'])->exists())
                        ? $row['R1_EMAIL']
                        : strtolower($row['R1_NOM'] . '.' . $row['R1_PRENOM'] . '@corriger.com'),
                    'password' => Hash::make(str()->random(10)),
                    'access' => true,
                ]);

                $infos = [
                    'phone' => !empty($row['R1_PORTABLENum']) ? '0' . ltrim($row['R1_PORTABLENum'], '0') : '',
                    'address' => $row['R1_ADRES 1'] ?? '',
                    'zipcode' => $row['R1_CP'] ?? '',
                    'city' => $row['R1_VILLE'] ?? '',
                    'birthdate' => !empty($row['R1_DATE NAISSANCE'])
                        ? \Carbon\Carbon::createFromFormat('d/m/Y', $row['R1_DATE NAISSANCE'])->format('Y-m-d')
                        : '',
                ];

                foreach ($infos as $key => $val) {
                    if ($val) {
                        UserInfo::updateOrCreate(['user_id' => $responsible->id, 'key' => $key], ['value' => $val]);
                    }
                }

                $family = Family::create();
                $family->school_id = 1;
                $family->save();

                $responsibleRole = Role::where('slug', 'responsible')->firstOrFail();

                UserRole::firstOrCreate([
                    'user_id' => $responsible->id,
                    'role_id' => $responsibleRole->id,
                    'roleable_type' => 'family',
                    'roleable_id' => $family->id,
                ]);

                $families[$r1_ident] = $family;

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("Erreur famille/responsable: " . $e->getMessage());
                continue;
            }
        }

        foreach ($records as $row) {
            $r1_ident = trim($row['R1_IDENT'] ?? '');
            $studentFirstname = trim($row['PRENOM'] ?? '');
            $studentLastname = trim($row['NOM'] ?? '');
            $studentBirthdate = null;
            if (!empty($row['DATE NAISS'])) {
                try {
                    $studentBirthdate = \Carbon\Carbon::createFromFormat('d/m/Y', $row['DATE NAISS'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $this->error("Date invalide pour élève {$studentLastname} {$studentFirstname} : {$row['DATE NAISS']}");
                }
            }
            $studentGender = strtoupper(trim($row['SEXE'] ?? 'M'));

            if (!empty($r1_ident) && isset($families[$r1_ident])) {
                // Élève rattaché à un responsable déjà créé
                $family = $families[$r1_ident];
            } elseif (empty($r1_ident)) {
                // Élève auto-responsable : vérifier correspondance avec un responsable existant
                $match = collect($records)->first(function ($testRow) use ($row) {
                    return !empty($testRow['R1_IDENT']) &&
                        trim(strtolower($testRow['R1_NOM'])) === trim(strtolower($row['NOM'])) &&
                        trim(strtolower($testRow['R1_PRENOM'])) === trim(strtolower($row['PRENOM'])) &&
                        trim(strtolower($testRow['R1_ADRES 1'])) === trim(strtolower($row['ADRES 1']));
                });

                if ($match) {
                    $matchedIdent = $match['R1_IDENT'];
                    if (!array_key_exists($matchedIdent, $families) || is_null($families[$matchedIdent])) {
                        $this->error("Responsable trouvé par correspondance mais non encore créé : {$matchedIdent}");
                        continue;
                    }
                    $family = $families[$matchedIdent];
                } else {
                    // Créer une nouvelle famille et responsable pour cet élève
                    DB::beginTransaction();
                    try {
                        $student = User::create([
                            'first_name' => $studentFirstname,
                            'last_name' => $studentLastname,
                            'email' => strtolower($studentFirstname . '.' . $studentLastname . '.' . uniqid() . '@autogen.fr'),
                            'password' => Hash::make(str()->random(10)),
                            'access' => true,
                        ]);

                        $family = Family::create();
                        $family->school_id = 1;
                        $family->save();

                        $responsibleRole = Role::where('slug', 'responsible')->firstOrFail();
                        $studentRole = Role::where('slug', 'student')->firstOrFail();

                        foreach ([$responsibleRole->id, $studentRole->id] as $roleId) {
                            UserRole::create([
                                'user_id' => $student->id,
                                'role_id' => $roleId,
                                'roleable_type' => 'family',
                                'roleable_id' => $family->id,
                            ]);
                        }

                        $infos = [
                            'birthdate' => $studentBirthdate,
                            'gender' => $studentGender,
                            'phone' => $row['PORTABLENum'] ?? '',
                            'address' => $row['ADRES 1'] ?? '',
                            'zipcode' => $row['CP'] ?? '',
                            'city' => $row['VILLE'] ?? '',
                        ];

                        foreach ($infos as $key => $val) {
                            if ($val) {
                                UserInfo::updateOrCreate(['user_id' => $student->id, 'key' => $key], ['value' => $val]);
                            }
                        }

                        DB::commit();
                        continue; // élève déjà créé, passer au suivant
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        $this->error("Erreur élève-responsable: " . $e->getMessage());
                        continue;
                    }
                }
            } else {
                // r1_ident non vide mais famille non trouvée (cas improbable)
                $this->error("Famille non trouvée pour R1_IDENT: {$r1_ident}");
                continue;
            }

            // Créer l'élève rattaché à la famille existante
            DB::beginTransaction();
            try {
                $student = User::create([
                    'first_name' => $studentFirstname,
                    'last_name' => $studentLastname,
                    'email' => strtolower($studentFirstname . '.' . $studentLastname . '.' . uniqid() . '@autogen.fr'),
                    'password' => Hash::make(str()->random(10)),
                    'access' => true,
                ]);

                if (!empty($studentBirthdate)) {
                    UserInfo::updateOrCreate(['user_id' => $student->id, 'key' => 'birthdate'], ['value' => $studentBirthdate]);
                }
                UserInfo::updateOrCreate(['user_id' => $student->id, 'key' => 'gender'], ['value' => $studentGender]);

                $studentRole = Role::where('slug', 'student')->firstOrFail();

                UserRole::create([
                    'user_id' => $student->id,
                    'role_id' => $studentRole->id,
                    'roleable_type' => 'family',
                    'roleable_id' => $family->id,
                ]);

                $school = School::findOrFail(1);
                $school->userRoles()->create([
                    'user_id' => $student->id,
                    'role_id' => $studentRole->id,
                ]);

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("Erreur ajout élève: " . $e->getMessage());
            }
        }

        $this->info("Import terminé avec succès.");
    }
}
