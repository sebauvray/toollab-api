<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Import d'élèves et de responsables de famille depuis un fichier Excel (.xlsx) ou CSV.
 *
 * Format attendu (1 feuille, 1 ligne = 1 élève, ligne 1 = en-têtes) :
 *   A Référence famille*   B Nom élève*   C Prénom élève*   D Date de naissance*   E Genre*
 *   F..L Responsable 1* (Nom, Prénom, Email, Téléphone, Adresse, Code postal, Ville)
 *   M..S Responsable 2  (mêmes colonnes, optionnel — mais si une est remplie, toutes le deviennent)
 *
 * Stratégie tout-ou-rien : si une seule ligne est invalide, aucune écriture n'a lieu
 * et le rapport d'erreurs (ligne / colonne / message) est renvoyé.
 */
class FamilyImportService
{
    private const MAX_DATA_ROWS = 5000;

    // Index de colonne (0-based) => libellé affiché dans les erreurs.
    private const COLS = [
        0 => 'Référence famille',
        1 => 'Nom élève',
        2 => 'Prénom élève',
        3 => 'Date de naissance',
        4 => 'Genre',
        5 => 'Nom responsable 1',
        6 => 'Prénom responsable 1',
        7 => 'Email responsable 1',
        8 => 'Téléphone responsable 1',
        9 => 'Adresse responsable 1',
        10 => 'Code postal responsable 1',
        11 => 'Ville responsable 1',
        12 => 'Nom responsable 2',
        13 => 'Prénom responsable 2',
        14 => 'Email responsable 2',
        15 => 'Téléphone responsable 2',
        16 => 'Adresse responsable 2',
        17 => 'Code postal responsable 2',
        18 => 'Ville responsable 2',
        19 => 'Élève est son propre responsable',
    ];

    /** @var array<int, array{row:int, colonne:string, message:string}> */
    private array $errors = [];

    private bool $tooManyRows = false;

    /**
     * Point d'entrée. Retourne un rapport :
     *   ok=false + errors  → rien n'a été importé
     *   ok=true  + summary → import effectué
     *
     * @return array<string, mixed>
     */
    public function import(string $filePath, string $originalName): array
    {
        $this->errors = [];
        $this->tooManyRows = false;

        try {
            $rows = $this->readRows($filePath, $originalName);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FamilyImport: lecture du fichier impossible', [
                'file' => $originalName,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            $message = 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit d\'un .xlsx ou .csv valide.';
            if (config('app.debug')) {
                $message .= ' [debug: '.get_class($e).' — '.$e->getMessage().']';
            }

            return [
                'ok' => false,
                'errors' => [[
                    'row' => 0,
                    'colonne' => '',
                    'cell' => '',
                    'valeur' => null,
                    'message' => $message,
                ]],
            ];
        }

        if ($this->tooManyRows) {
            return [
                'ok' => false,
                'errors' => [[
                    'row' => 0,
                    'colonne' => '',
                    'cell' => '',
                    'valeur' => null,
                    'message' => 'Le fichier dépasse la limite de '.self::MAX_DATA_ROWS.' lignes de données. Découpez-le en plusieurs imports.',
                ]],
            ];
        }

        if (! $this->validateHeader($rows[0] ?? null)) {
            return [
                'ok' => false,
                'errors' => [[
                    'row' => 1,
                    'colonne' => '',
                    'message' => 'Les en-têtes du fichier ne correspondent pas au modèle attendu. '
                        .'Téléchargez le modèle et conservez la première ligne d\'en-têtes.',
                ]],
            ];
        }

        $families = $this->buildFamilies($rows);

        if (! empty($this->errors)) {
            return ['ok' => false, 'errors' => $this->sortedErrors()];
        }

        if (empty($families)) {
            return [
                'ok' => false,
                'errors' => [[
                    'row' => 0,
                    'colonne' => '',
                    'message' => 'Aucune ligne de données à importer.',
                ]],
            ];
        }

        $this->validateNoExistingStudents($families);

        if (! empty($this->errors)) {
            return ['ok' => false, 'errors' => $this->sortedErrors()];
        }

        return $this->persist($families);
    }

    /**
     * Lit toutes les lignes non vides du fichier en tableaux de chaînes normalisées.
     *
     * @return array<int, array{cells: array<int, string>, line: int}>
     */
    private function readRows(string $filePath, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = $this->sniffDelimiter($filePath);
            $options->ENCODING = 'UTF-8';
            $reader = new CsvReader($options);
        } else {
            $reader = new XlsxReader();
        }

        $reader->open($filePath);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $line => $row) {
                $cells = array_map(
                    fn ($value) => $this->normalizeCell($value),
                    $row->toArray()
                );

                // Ignore les lignes entièrement vides.
                if (implode('', $cells) === '') {
                    continue;
                }

                $rows[] = ['cells' => $cells, 'line' => $line];

                if (count($rows) > self::MAX_DATA_ROWS + 1) {
                    $this->tooManyRows = true;
                    break 2;
                }
            }
            break; // uniquement la première feuille
        }

        $reader->close();

        return $rows;
    }

    private function sniffDelimiter(string $filePath): string
    {
        $firstLine = '';
        $handle = @fopen($filePath, 'r');
        if ($handle) {
            $firstLine = (string) fgets($handle);
            fclose($handle);
        }

        return substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    }

    private function normalizeCell(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value)) {
            // Évite "75011.0" et la perte de précision sur les entiers.
            if (floor($value) === $value && is_finite($value)) {
                return (string) (int) $value;
            }

            return (string) $value;
        }

        return trim((string) $value);
    }

    private function validateHeader(?array $header): bool
    {
        if (! $header || count($header['cells'] ?? []) < 12) {
            return false;
        }

        $cells = $header['cells'];
        $norm = fn (int $i) => $this->slug($cells[$i] ?? '');

        return str_contains($norm(0), 'reference')
            && str_contains($norm(3), 'naissance')
            && str_contains($norm(4), 'genre');
    }

    /**
     * Regroupe les lignes de données par référence famille et valide chaque champ.
     *
     * @param  array<int, array{cells: array<int, string>, line: int}>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildFamilies(array $rows): array
    {
        $families = [];
        $seenStudents = [];

        foreach (array_slice($rows, 1) as $row) {
            $line = $row['line'];
            $c = $row['cells'];

            $ref = $this->cell($c, 0);
            if ($ref === '') {
                $this->addError($line, 0, 'La référence famille est vide. Chaque ligne doit en avoir une (ex. FAM001) ; les élèves d\'une même famille partagent la même.', '');

                continue;
            }

            $families[$ref] ??= ['ref' => $ref, 'line' => $line, 'students' => [], 'adults' => [], 'responsibles' => []];

            // --- Identité + données élève (obligatoires sur chaque ligne) ---
            $person = [
                'last_name' => $this->cell($c, 1),
                'first_name' => $this->cell($c, 2),
                'birthdate' => $this->normalizeDate($this->cell($c, 3)),
                'gender' => $this->normalizeGender($this->cell($c, 4)),
                'line' => $line,
            ];

            if ($person['last_name'] === '') {
                $this->addError($line, 1, 'Le nom de l\'élève est vide. Renseignez-le.', '');
            }
            if ($person['first_name'] === '') {
                $this->addError($line, 2, 'Le prénom de l\'élève est vide. Renseignez-le.', '');
            }
            if ($this->cell($c, 3) === '') {
                $this->addError($line, 3, 'La date de naissance de l\'élève est vide. Format attendu : AAAA-MM-JJ (ex. 2015-03-12).', '');
            } elseif ($person['birthdate'] === null) {
                $this->addError($line, 3, 'Date de naissance non reconnue. Format attendu : AAAA-MM-JJ (ex. 2015-03-12).', $this->cell($c, 3));
            }
            if ($this->cell($c, 4) === '') {
                $this->addError($line, 4, 'Le genre de l\'élève est vide. Indiquez M (masculin) ou F (féminin).', '');
            } elseif ($person['gender'] === null) {
                $this->addError($line, 4, 'Genre non reconnu. Valeurs acceptées : M (masculin) ou F (féminin).', $this->cell($c, 4));
            }

            // Anti-doublon élève (même nom + prénom + date dans la famille).
            $dupKey = $this->slug($person['last_name'].'|'.$person['first_name'].'|'.($person['birthdate'] ?? ''));
            $scopedKey = $ref.'::'.$dupKey;
            if ($person['last_name'] !== '' && isset($seenStudents[$scopedKey])) {
                $this->addError($line, 1, 'Élève en double : apparaît déjà avec la même date de naissance dans la famille "'.$ref.'".', trim($person['first_name'].' '.$person['last_name']));
            } else {
                $seenStudents[$scopedKey] = true;
            }

            // --- Responsable 1 : obligatoire et renseigné sur CHAQUE ligne ---
            $resp1 = $this->parseResponsible($c, $line, 5, 'Responsable 1');

            // --- L'élève est-il son propre responsable ? ---
            if ($this->isYes($this->cell($c, 19))) {
                // Une seule personne, deux rôles : construite à partir du Responsable 1
                // (déjà validé) + la date de naissance et le genre de l'élève.
                $families[$ref]['adults'][] = [
                    'last_name' => $resp1['last_name'] !== '' ? $resp1['last_name'] : $person['last_name'],
                    'first_name' => $resp1['first_name'] !== '' ? $resp1['first_name'] : $person['first_name'],
                    'email' => $resp1['email'],
                    'phone' => $resp1['phone'],
                    'address' => $resp1['address'],
                    'zipcode' => $resp1['zipcode'],
                    'city' => $resp1['city'],
                    'birthdate' => $person['birthdate'],
                    'gender' => $person['gender'],
                    'line' => $line,
                ];
            } else {
                $families[$ref]['students'][] = $person;
                if ($resp1['email'] !== '') {
                    $families[$ref]['responsibles'][$resp1['email']] = $resp1;
                }
            }

            // --- Responsable 2 : optionnel (mais si une colonne est remplie, toutes le deviennent) ---
            if ($this->hasAny($c, range(12, 18))) {
                $resp2 = $this->parseResponsible($c, $line, 12, 'Responsable 2');
                if ($resp2['email'] !== '') {
                    $families[$ref]['responsibles'][$resp2['email']] = $resp2;
                }
            }
        }

        return $families;
    }

    /**
     * Lit et valide un bloc responsable démarrant à l'index $base.
     *
     * @return array<string, string>
     */
    private function parseResponsible(array $c, int $line, int $base, string $label): array
    {
        $fields = [
            'last_name' => $this->cell($c, $base + 0),
            'first_name' => $this->cell($c, $base + 1),
            'email' => $this->cell($c, $base + 2),
            'phone' => $this->cell($c, $base + 3),
            'address' => $this->cell($c, $base + 4),
            'zipcode' => $this->cell($c, $base + 5),
            'city' => $this->cell($c, $base + 6),
        ];

        $labels = [
            'last_name' => 'le nom', 'first_name' => 'le prénom', 'email' => 'l\'email',
            'phone' => 'le téléphone', 'address' => 'l\'adresse', 'zipcode' => 'le code postal', 'city' => 'la ville',
        ];
        $offsets = ['last_name' => 0, 'first_name' => 1, 'email' => 2, 'phone' => 3, 'address' => 4, 'zipcode' => 5, 'city' => 6];

        foreach ($fields as $key => $value) {
            if ($value === '') {
                $this->addError($line, $base + $offsets[$key], ucfirst($labels[$key]).' du '.strtolower($label).' est vide. Renseignez-le.', '');
            }
        }

        if ($fields['email'] !== '' && ! filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $this->addError($line, $base + 2, 'Email du '.strtolower($label).' invalide (ex. prenom.nom@email.com).', $fields['email']);
        }

        return $fields;
    }

    /**
     * Refuse l'import si un élève semble déjà exister dans l'école courante.
     * Le fichier ne contient pas d'identifiant stable, donc on compare identité + naissance.
     *
     * @param  array<string, array<string, mixed>>  $families
     */
    private function validateNoExistingStudents(array $families): void
    {
        $studentRoleId = Role::where('slug', 'student')->value('id');
        if (! $studentRoleId) {
            return;
        }

        foreach ($families as $familyData) {
            $students = array_merge($familyData['students'], $familyData['adults']);

            foreach ($students as $student) {
                if (($student['birthdate'] ?? null) === null) {
                    continue;
                }

                $exists = UserRole::query()
                    ->where('role_id', $studentRoleId)
                    ->where('roleable_type', 'family')
                    ->whereHasMorph('roleable', [Family::class])
                    ->whereHas('user', function ($query) use ($student) {
                        $query->where('first_name', $student['first_name'])
                            ->where('last_name', $student['last_name'])
                            ->whereHas('infos', function ($infoQuery) use ($student) {
                                $infoQuery->where('key', 'birthdate')
                                    ->where('value', $student['birthdate']);
                            });
                    })
                    ->exists();

                if ($exists) {
                    $this->addError(
                        (int) $student['line'],
                        1,
                        'Cet élève existe déjà dans cette école avec le même nom, prénom et date de naissance. Vérifiez que le fichier n\'a pas déjà été importé.',
                        trim($student['first_name'].' '.$student['last_name'])
                    );
                }
            }
        }
    }

    /**
     * Écrit familles, responsables et élèves dans une transaction unique.
     *
     * @param  array<string, array<string, mixed>>  $families
     * @return array<string, mixed>
     */
    private function persist(array $families): array
    {
        $responsibleRole = Role::where('slug', 'responsible')->firstOrFail();
        $studentRole = Role::where('slug', 'student')->firstOrFail();

        $summary = [
            'families' => 0,
            'students' => 0,
            'responsibles_created' => 0,
            'responsibles_reused' => 0,
        ];

        DB::transaction(function () use ($families, $responsibleRole, $studentRole, &$summary) {
            foreach ($families as $data) {
                $family = Family::create();
                $summary['families']++;

                foreach ($data['responsibles'] as $resp) {
                    $reused = $this->attachResponsible($family, $resp, $responsibleRole->id);
                    $summary[$reused ? 'responsibles_reused' : 'responsibles_created']++;
                }

                foreach ($data['adults'] as $adult) {
                    $reused = $this->persistAdultStudent($family, $adult, $responsibleRole->id, $studentRole->id);
                    $summary[$reused ? 'responsibles_reused' : 'responsibles_created']++;
                    $summary['students']++;
                }

                foreach ($data['students'] as $student) {
                    $this->createStudent($family, $student, $studentRole->id);
                    $summary['students']++;
                }
            }
        });

        return ['ok' => true, 'summary' => $summary];
    }

    /**
     * Crée (ou réutilise) une personne majeure qui est à la fois élève et responsable
     * de sa propre famille : un seul utilisateur, deux rôles.
     *
     * @param  array<string, mixed>  $adult
     * @return bool  true si l'utilisateur existait déjà (réutilisé)
     */
    private function persistAdultStudent(Family $family, array $adult, int $responsibleRoleId, int $studentRoleId): bool
    {
        $user = User::where('email', $adult['email'])->first();
        $reused = $user !== null;

        if (! $user) {
            $user = User::create([
                'first_name' => $adult['first_name'],
                'last_name' => $adult['last_name'],
                'email' => $adult['email'],
                'password' => Hash::make(str()->random(16)),
                'access' => true,
            ]);

            $this->setInfo($user, 'phone', $adult['phone']);
            $this->setInfo($user, 'address', $adult['address']);
            $this->setInfo($user, 'zipcode', $adult['zipcode']);
            $this->setInfo($user, 'city', $adult['city']);
            $this->setInfo($user, 'birthdate', $adult['birthdate']);
            $this->setInfo($user, 'gender', $adult['gender']);
        } else {
            $this->setInfoIfMissing($user, 'phone', $adult['phone']);
            $this->setInfoIfMissing($user, 'address', $adult['address']);
            $this->setInfoIfMissing($user, 'zipcode', $adult['zipcode']);
            $this->setInfoIfMissing($user, 'city', $adult['city']);
            $this->setInfoIfMissing($user, 'birthdate', $adult['birthdate']);
            $this->setInfoIfMissing($user, 'gender', $adult['gender']);
        }

        $family->userRoles()->firstOrCreate(['user_id' => $user->id, 'role_id' => $responsibleRoleId]);
        $family->userRoles()->firstOrCreate(['user_id' => $user->id, 'role_id' => $studentRoleId]);

        return $reused;
    }

    /**
     * @param  array<string, string>  $fields
     * @return bool  true si l'utilisateur existait déjà (réutilisé)
     */
    private function attachResponsible(Family $family, array $fields, int $roleId): bool
    {
        $user = User::where('email', $fields['email'])->first();
        $reused = $user !== null;

        if (! $user) {
            $user = User::create([
                'first_name' => $fields['first_name'],
                'last_name' => $fields['last_name'],
                'email' => $fields['email'],
                'password' => Hash::make(str()->random(16)),
                'access' => true,
            ]);

            $this->setInfo($user, 'phone', $fields['phone']);
            $this->setInfo($user, 'address', $fields['address']);
            $this->setInfo($user, 'zipcode', $fields['zipcode']);
            $this->setInfo($user, 'city', $fields['city']);
        } else {
            $this->setInfoIfMissing($user, 'phone', $fields['phone']);
            $this->setInfoIfMissing($user, 'address', $fields['address']);
            $this->setInfoIfMissing($user, 'zipcode', $fields['zipcode']);
            $this->setInfoIfMissing($user, 'city', $fields['city']);
        }

        $family->userRoles()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);

        return $reused;
    }

    /**
     * @param  array<string, mixed>  $student
     */
    private function createStudent(Family $family, array $student, int $roleId): void
    {
        $email = strtolower(
            $this->slug($student['first_name']).'.'.$this->slug($student['last_name'])
            .'.student.'.uniqid().'@school.com'
        );

        $user = User::create([
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'email' => $email,
            'password' => Hash::make(str()->random(16)),
            'access' => true,
        ]);

        $this->setInfo($user, 'birthdate', $student['birthdate']);
        $this->setInfo($user, 'gender', $student['gender']);

        $family->userRoles()->create([
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);
    }

    private function setInfo(User $user, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        UserInfo::updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['value' => $value]
        );
    }

    private function setInfoIfMissing(User $user, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        UserInfo::firstOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['value' => $value]
        );
    }

    private function cell(array $cells, int $index): string
    {
        return trim($cells[$index] ?? '');
    }

    private function hasAny(array $cells, array $indexes): bool
    {
        foreach ($indexes as $i) {
            if ($this->cell($cells, $i) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $raw);
            $errors = \DateTime::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($date && $clean) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function isYes(string $raw): bool
    {
        return in_array($this->slug($raw, true), ['OUI', 'O', 'YES', 'Y', 'TRUE', 'VRAI', '1', 'X'], true);
    }

    private function normalizeGender(string $raw): ?string
    {
        $map = [
            'M' => 'M', 'MASCULIN' => 'M', 'GARCON' => 'M', 'H' => 'M', 'HOMME' => 'M',
            'F' => 'F', 'FEMININ' => 'F', 'FILLE' => 'F', 'FEMME' => 'F',
        ];

        return $map[$this->slug($raw, true)] ?? null;
    }

    /**
     * Normalise une chaîne : minuscules, sans accents, sans espaces superflus.
     * En mode $upper, retourne en majuscules (pour comparer le genre).
     */
    private function slug(string $value, bool $upper = false): string
    {
        $value = trim($value);
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ñ' => 'n',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        ]);

        return $upper ? strtoupper($value) : strtolower($value);
    }

    private function addError(int $row, int $colIndex, string $message, ?string $value = null): void
    {
        $cell = ($colIndex >= 0 && $row > 0) ? $this->columnLetter($colIndex + 1).$row : '';

        $this->errors[] = [
            'row' => $row,
            'colonne' => self::COLS[$colIndex] ?? '',
            'cell' => $cell,
            'valeur' => $value,
            'message' => $message,
        ];
    }

    private function columnLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    /**
     * @return array<int, array{row:int, colonne:string, cell:string, message:string}>
     */
    private function sortedErrors(): array
    {
        $errors = $this->errors;
        usort($errors, fn ($a, $b) => [$a['row'], $a['cell']] <=> [$b['row'], $b['cell']]);

        return array_slice($errors, 0, 200);
    }
}
