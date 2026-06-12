<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Role;
use App\Models\Cursus;
use App\Models\CursusLevel;
use App\Models\SchoolYear;
use App\Models\Tarif;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Family;
use App\Models\StudentClassroom;
use App\Models\Paiement;
use App\Models\LignePaiement;
use App\Models\UserInfo;
use App\Models\ReductionFamiliale;
use App\Models\ReductionMultiCursus;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ToollabSeeder extends Seeder
{
    private $faker;
    private $school;
    private $schoolYear;
    private $cursusArabe;
    private $cursusCoran;
    private $levelsArabe;
    private $classrooms = ['arabe' => [], 'coran' => []];
    private $classroomCounts = [];
    private $families = [];
    private $students = [];
    private $responsibles = [];
    private $teachers = [];
    private $enrollmentPlan = [];

    private const BANQUES = [
        'BNP Paribas', 'Crédit Agricole', 'Société Générale', 'LCL',
        'La Banque Postale', 'Caisse d\'Épargne', 'Crédit Mutuel', 'Boursorama Banque',
    ];

    public function __construct()
    {
        $this->faker = Faker::create('fr_FR');
    }

    public function run(): void
    {
        $this->createSchoolAndDirector();
        $this->createCursusEtTarification();
        $this->createClasses();
        $this->createTeachersAndSchedules();
        $this->createFamilies();
        $this->createStudents();
        $this->enrollStudentsInClasses();
        $this->createPayments();
    }

    private function createSchoolAndDirector(): void
    {
        $this->school = School::firstOrCreate(
            ['email' => 'contact@alhikma.fr'],
            [
                'name' => 'École Arabe Al-Hikma',
                'address' => '123 rue de la Connaissance',
                'zipcode' => '75015',
                'city' => 'Paris',
                'country' => 'France',
                'access' => 1,
            ]
        );

        $director = User::firstOrCreate(
            ['email' => 'relhanti@gmail.com'],
            [
                'first_name' => 'Directeur',
                'last_name' => 'Principal',
                'password' => Hash::make('password'),
                'access' => 1,
            ]
        );

        $directorRole = Role::where('slug', 'director')->first();
        UserRole::firstOrCreate([
            'user_id' => $director->id,
            'role_id' => $directorRole->id,
            'roleable_type' => 'school',
            'roleable_id' => $this->school->id,
        ]);

        $now = Carbon::now();
        $startYear = $now->month >= 9 ? $now->year : $now->year - 1;
        $yearLabel = $startYear . '-' . ($startYear + 1);

        $this->schoolYear = SchoolYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('label', $yearLabel)
            ->first();

        if (!$this->schoolYear) {
            $this->schoolYear = new SchoolYear([
                'label' => $yearLabel,
                'opened_at' => $now,
                'is_active' => true,
            ]);
            $this->schoolYear->school_id = $this->school->id;
            $this->schoolYear->save();
        }

        // Mock du contexte HTTP pour que les traits BelongsToSchool / BelongsToSchoolYear
        // auto-set les FK sur les modèles créés par le seeder.
        request()->attributes->set('current_school_id', $this->school->id);
        request()->attributes->set('current_school_year_id', $this->schoolYear->id);
    }

    private function createCursusEtTarification(): void
    {
        $director = User::where('email', 'relhanti@gmail.com')->first();

        $this->cursusArabe = Cursus::create([
            'name' => 'Arabe',
            'progression' => 'levels',
            'school_id' => $this->school->id,
        ]);

        $this->levelsArabe = collect();
        $levelNames = ['1ère année', '2ème année', '3ème année', '4ème année', '5ème année'];
        foreach ($levelNames as $i => $name) {
            $this->levelsArabe->push(CursusLevel::create([
                'cursus_id' => $this->cursusArabe->id,
                'name' => $name,
                'order' => $i + 1,
            ]));
        }

        Tarif::create([
            'cursus_id' => $this->cursusArabe->id,
            'prix' => 270,
            'actif' => true,
        ]);

        // 270 € → 240 € dès 3 élèves (−11,11 %) → 210 € dès 5 élèves (−22,22 %)
        ReductionFamiliale::create([
            'cursus_id' => $this->cursusArabe->id,
            'nombre_eleves_min' => 3,
            'pourcentage_reduction' => 11.11,
            'actif' => true,
            'created_by' => $director->id,
        ]);
        ReductionFamiliale::create([
            'cursus_id' => $this->cursusArabe->id,
            'nombre_eleves_min' => 5,
            'pourcentage_reduction' => 22.22,
            'actif' => true,
            'created_by' => $director->id,
        ]);

        $this->cursusCoran = Cursus::create([
            'name' => 'Coran',
            'progression' => 'continu',
            'school_id' => $this->school->id,
        ]);

        Tarif::create([
            'cursus_id' => $this->cursusCoran->id,
            'prix' => 150,
            'actif' => true,
        ]);

        ReductionMultiCursus::create([
            'cursus_beneficiaire_id' => $this->cursusCoran->id,
            'cursus_requis_id' => $this->cursusArabe->id,
            'pourcentage_reduction' => 50,
            'actif' => true,
            'created_by' => $director->id,
        ]);
    }

    private function createClasses(): void
    {
        $genders = ['Enfants', 'Femmes', 'Hommes'];
        $shortLevels = ['1ère', '2ème', '3ème', '4ème', '5ème'];

        foreach ($this->levelsArabe as $i => $level) {
            foreach (['A', 'B', 'C'] as $j => $letter) {
                $classroom = Classroom::create([
                    'school_id' => $this->school->id,
                    'name' => $shortLevels[$i] . ' ' . $letter,
                    'years' => date('Y'),
                    'type' => 'Arabe',
                    'size' => $this->faker->randomElement([15, 20, 25]),
                    'cursus_id' => $this->cursusArabe->id,
                    'level_id' => $level->id,
                    'gender' => $genders[$j],
                ]);
                $this->classrooms['arabe'][] = $classroom;
                $this->classroomCounts[$classroom->id] = 0;
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $j => $letter) {
            $classroom = Classroom::create([
                'school_id' => $this->school->id,
                'name' => 'Coran ' . $letter,
                'years' => date('Y'),
                'type' => 'Coran',
                'size' => $this->faker->randomElement([15, 20, 25]),
                'cursus_id' => $this->cursusCoran->id,
                'level_id' => null,
                'gender' => $genders[$j % 3],
            ]);
            $this->classrooms['coran'][] = $classroom;
            $this->classroomCounts[$classroom->id] = 0;
        }
    }

    private function createTeachersAndSchedules(): void
    {
        $teacherRole = Role::where('slug', 'teacher')->first();

        $teacherSeeds = [
            ['first_name' => 'Samira', 'last_name' => 'Benali', 'email' => 'samira.benali@alhikma.fr'],
            ['first_name' => 'Faouziya', 'last_name' => 'Tazi', 'email' => 'faouziya.tazi@alhikma.fr'],
            ['first_name' => 'Isabelle', 'last_name' => 'Martin', 'email' => 'isabelle.martin@alhikma.fr'],
            ['first_name' => 'Asma', 'last_name' => 'Hassani', 'email' => 'asma.hassani@alhikma.fr'],
            ['first_name' => 'Karima', 'last_name' => 'Boudiaf', 'email' => 'karima.boudiaf@alhikma.fr'],
            ['first_name' => 'Ilyas', 'last_name' => 'Mansour', 'email' => 'ilyas.mansour@alhikma.fr'],
            ['first_name' => 'Abdessamad', 'last_name' => 'Idrissi', 'email' => 'abdessamad.idrissi@alhikma.fr'],
            ['first_name' => 'Amina', 'last_name' => 'Fassi', 'email' => 'amina.fassi@alhikma.fr'],
            ['first_name' => 'Bayie', 'last_name' => 'Ouali', 'email' => 'bayie.ouali@alhikma.fr'],
        ];

        foreach ($teacherSeeds as $seed) {
            $user = User::firstOrCreate(
                ['email' => $seed['email']],
                [
                    'first_name' => $seed['first_name'],
                    'last_name' => $seed['last_name'],
                    'password' => Hash::make('password'),
                    'access' => 1,
                ]
            );

            UserRole::firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $teacherRole->id,
                'roleable_type' => 'school',
                'roleable_id' => $this->school->id,
            ]);

            $this->teachers[] = $user;
        }

        $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $slots = [
            ['09:00', '11:00'],
            ['10:00', '12:00'],
            ['11:00', '13:00'],
            ['14:00', '16:00'],
            ['15:30', '17:30'],
            ['17:00', '19:00'],
        ];

        $allClassrooms = array_merge($this->classrooms['arabe'], $this->classrooms['coran']);
        foreach ($allClassrooms as $classroom) {
            $nbSchedules = $this->faker->numberBetween(1, 2);
            $usedKeys = [];
            $mainTeacherId = null;

            for ($i = 0; $i < $nbSchedules; $i++) {
                $attempt = 0;
                do {
                    $day = $this->faker->randomElement($days);
                    $slot = $this->faker->randomElement($slots);
                    $key = $day . '|' . $slot[0];
                    $attempt++;
                } while (in_array($key, $usedKeys, true) && $attempt < 10);

                if (in_array($key, $usedKeys, true)) continue;
                $usedKeys[] = $key;

                $teacher = $this->faker->randomElement($this->teachers);
                $mainTeacherId ??= $teacher->id;

                ClassSchedule::create([
                    'classroom_id' => $classroom->id,
                    'teacher_id' => $teacher->id,
                    'day' => $day,
                    'start_time' => $slot[0],
                    'end_time' => $slot[1],
                ]);
            }

            if ($mainTeacherId) {
                $classroom->main_teacher_id = $mainTeacherId;
                $classroom->save();
            }
        }
    }

    private function createFamilies(): void
    {
        $responsibleRole = Role::where('slug', 'responsible')->first();

        for ($i = 1; $i <= 60; $i++) {
            $family = Family::create([
                'school_id' => $this->school->id,
            ]);

            if ($i <= 30) {
                $responsible = $this->createResponsible('female');
            } elseif ($i <= 50) {
                $responsible = $this->createResponsible('male');
            } else {
                $responsible1 = $this->createResponsible('female');
                $responsible2 = $this->createResponsible('male', $responsible1->last_name);

                UserRole::create([
                    'user_id' => $responsible2->id,
                    'role_id' => $responsibleRole->id,
                    'roleable_type' => 'family',
                    'roleable_id' => $family->id,
                ]);

                $responsible = $responsible1;
            }

            UserRole::create([
                'user_id' => $responsible->id,
                'role_id' => $responsibleRole->id,
                'roleable_type' => 'family',
                'roleable_id' => $family->id,
            ]);

            $this->families[$family->id] = [
                'family' => $family,
                'responsible' => $responsible,
                'students' => []
            ];
        }
    }

    private function createResponsible($gender, $lastName = null): User
    {
        $firstName = $gender === 'female'
            ? $this->faker->firstNameFemale
            : $this->faker->firstNameMale;

        $lastName = $lastName ?: $this->faker->lastName;

        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => Hash::make('password'),
            'access' => 1,
        ]);

        $this->addResponsibleInfos($user);

        $this->responsibles[] = $user;
        return $user;
    }

    private function addResponsibleInfos(User $user): void
    {
        $phoneNumber = '0' . $this->faker->randomElement(['6', '7']) . $this->faker->numerify('########');
        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'phone',
            'value' => $phoneNumber,
        ]);

        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'address',
            'value' => $this->faker->streetAddress,
        ]);

        $zipcode = $this->faker->randomElement(['75', '92', '93', '94', '95', '77', '78', '91']) . $this->faker->numerify('###');
        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'zipcode',
            'value' => $zipcode,
        ]);

        $cities = [
            '75' => 'Paris',
            '92' => ['Neuilly-sur-Seine', 'Boulogne-Billancourt', 'Issy-les-Moulineaux', 'Levallois-Perret'],
            '93' => ['Saint-Denis', 'Montreuil', 'Aubervilliers', 'Pantin'],
            '94' => ['Créteil', 'Vitry-sur-Seine', 'Saint-Maur-des-Fossés', 'Ivry-sur-Seine'],
            '95' => ['Argenteuil', 'Sarcelles', 'Cergy', 'Garges-lès-Gonesse'],
            '77' => ['Melun', 'Meaux', 'Chelles', 'Pontault-Combault'],
            '78' => ['Versailles', 'Sartrouville', 'Mantes-la-Jolie', 'Saint-Germain-en-Laye'],
            '91' => ['Évry', 'Corbeil-Essonnes', 'Massy', 'Savigny-sur-Orge'],
        ];

        $dept = substr($zipcode, 0, 2);
        $city = is_array($cities[$dept]) ? $this->faker->randomElement($cities[$dept]) : $cities[$dept];

        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'city',
            'value' => $city,
        ]);
    }

    private function createStudents(): void
    {
        $studentRole = Role::where('slug', 'student')->first();

        $studentDistribution = $this->generateStudentDistribution();

        foreach ($this->families as $familyId => &$familyData) {
            $numberOfStudents = array_shift($studentDistribution);

            for ($i = 0; $i < $numberOfStudents; $i++) {
                $isChild = $this->faker->boolean(70);

                $student = User::create([
                    'first_name' => $this->faker->firstName,
                    'last_name' => $familyData['responsible']->last_name,
                    'email' => $this->faker->unique()->safeEmail,
                    'password' => Hash::make('password'),
                    'access' => 1,
                ]);

                $student->is_child = $isChild;

                UserRole::create([
                    'user_id' => $student->id,
                    'role_id' => $studentRole->id,
                    'roleable_type' => 'family',
                    'roleable_id' => $familyId,
                ]);

                $birthdate = $isChild
                    ? $this->faker->dateTimeBetween('-15 years', '-6 years')
                    : $this->faker->dateTimeBetween('-30 years', '-16 years');

                UserInfo::create([
                    'user_id' => $student->id,
                    'key' => 'birthdate',
                    'value' => $birthdate->format('Y-m-d'),
                ]);

                $gender = $this->faker->randomElement(['M', 'F']);
                UserInfo::create([
                    'user_id' => $student->id,
                    'key' => 'gender',
                    'value' => $gender,
                ]);

                $student->gender = $gender;

                $familyData['students'][] = $student;
                $this->students[] = $student;
            }
        }
    }

    private function generateStudentDistribution(): array
    {
        $distribution = array_merge(
            array_fill(0, 8, 1),
            array_fill(0, 14, 2),
            array_fill(0, 14, 3),
            array_fill(0, 12, 4),
            array_fill(0, 8, 5),
            array_fill(0, 4, 6),
        );

        shuffle($distribution);
        return $distribution;
    }

    /**
     * Profils famille : 15 % aucun inscrit, 25 % partiellement inscrits, 60 % tous inscrits.
     * Par élève inscrit : 75 % Arabe seul, 20 % Coran seul, 5 % les deux. Jamais plus de 2 cursus.
     */
    private function enrollStudentsInClasses(): void
    {
        foreach ($this->families as $familyId => $familyData) {
            $profile = $this->faker->randomElement(array_merge(
                array_fill(0, 3, 'none'),
                array_fill(0, 5, 'partial'),
                array_fill(0, 12, 'all'),
            ));

            $plan = ['arabe' => 0, 'coran_only' => 0, 'both' => 0];

            foreach ($familyData['students'] as $student) {
                $enroll = match ($profile) {
                    'none' => false,
                    'partial' => $this->faker->boolean(55),
                    default => true,
                };
                if (!$enroll) continue;

                $roll = $this->faker->numberBetween(1, 100);
                $wantsArabe = $roll <= 80;
                $wantsCoran = $roll > 75;

                $inArabe = $wantsArabe && $this->enrollInCursus($student, $familyId, 'arabe');
                $inCoran = $wantsCoran && $this->enrollInCursus($student, $familyId, 'coran');

                if ($inArabe && $inCoran) {
                    $plan['both']++;
                    $plan['arabe']++;
                } elseif ($inArabe) {
                    $plan['arabe']++;
                } elseif ($inCoran) {
                    $plan['coran_only']++;
                }
            }

            $this->enrollmentPlan[$familyId] = $plan;
        }
    }

    private function enrollInCursus(User $student, int $familyId, string $cursusKey): bool
    {
        $gender = $student->is_child ? 'Enfants' : ($student->gender === 'F' ? 'Femmes' : 'Hommes');

        $candidates = array_filter(
            $this->classrooms[$cursusKey],
            fn ($c) => $c->gender === $gender && $this->classroomCounts[$c->id] < $c->size
        );
        if (empty($candidates)) return false;

        $classroom = $this->faker->randomElement(array_values($candidates));

        StudentClassroom::create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'family_id' => $familyId,
            'status' => 'active',
            'enrollment_date' => Carbon::now()->subDays(rand(1, 45)),
        ]);
        $this->classroomCounts[$classroom->id]++;

        return true;
    }

    /**
     * Total famille fidèle au TarifCalculatorService :
     * Arabe = 270 € / élève, 240 € dès 3 élèves Arabe, 210 € dès 5.
     * Coran = 150 € / élève, 75 € si l'élève suit aussi l'Arabe (réduction multi-cursus 50 %, exclusive).
     */
    private function computeFamilyTotal(array $plan): int
    {
        $nArabe = $plan['arabe'];
        $prixArabe = $nArabe >= 5 ? 210 : ($nArabe >= 3 ? 240 : 270);

        return $prixArabe * $nArabe
            + 150 * $plan['coran_only']
            + 75 * $plan['both'];
    }

    /**
     * Scénarios : 25 % rien payé, 35 % payé en totalité, 30 % paiement partiel,
     * 10 % exonération (totale ou partielle). Jamais de dépassement du total dû.
     */
    private function createPayments(): void
    {
        $director = User::where('email', 'relhanti@gmail.com')->first();

        foreach ($this->families as $familyId => $familyData) {
            $plan = $this->enrollmentPlan[$familyId] ?? null;
            if (!$plan) continue;

            $total = $this->computeFamilyTotal($plan);
            if ($total <= 0) continue;

            $scenario = $this->faker->randomElement(array_merge(
                array_fill(0, 25, 'unpaid'),
                array_fill(0, 35, 'full'),
                array_fill(0, 30, 'partial'),
                array_fill(0, 10, 'exoneration'),
            ));

            if ($scenario === 'unpaid') continue;

            $paiement = Paiement::create([
                'family_id' => $familyId,
                'created_by' => $director->id,
            ]);

            $responsible = $familyData['responsible'];

            if ($scenario === 'exoneration') {
                $exoTotale = $this->faker->boolean(50);
                $montantExo = $exoTotale ? $total : (int) round($total / 2);

                LignePaiement::create([
                    'paiement_id' => $paiement->id,
                    'type_paiement' => 'exoneration',
                    'montant' => $montantExo,
                    'details' => [
                        'justification' => $this->faker->randomElement([
                            'Situation sociale difficile',
                            'Famille du personnel de l\'institut',
                            'Prise en charge association partenaire',
                        ]),
                    ],
                    'created_by' => $director->id,
                ]);

                if (!$exoTotale && $this->faker->boolean(70)) {
                    $this->createPaymentLines($paiement, $total - $montantExo, $responsible, $director);
                }
                continue;
            }

            $aPayer = $scenario === 'full'
                ? $total
                : (int) round($total * $this->faker->randomElement([0.3, 0.4, 0.5, 0.6, 0.75]));

            if ($aPayer <= 0) continue;

            $this->createPaymentLines($paiement, $aPayer, $responsible, $director);
        }
    }

    /**
     * Répartit un montant en lignes cohérentes : chèques (1 à 3, infos complètes au
     * format API {banque, numero, nom_emetteur}), CB seule, espèces seules, ou mix.
     */
    private function createPaymentLines(Paiement $paiement, int $montant, User $responsible, User $director): void
    {
        $mode = $this->faker->randomElement([
            'cheques3', 'cheques3', 'cheque1', 'cheque1', 'cheque1',
            'carte', 'carte', 'espece', 'espece',
            'mix_espece_cheque', 'mix_carte_espece',
        ]);

        $lignes = [];

        switch ($mode) {
            case 'cheques3':
                $n = min(3, max(2, intdiv($montant, 100) ?: 2));
                $part = intdiv($montant, $n);
                for ($i = 0; $i < $n; $i++) {
                    $m = $i === $n - 1 ? $montant - $part * ($n - 1) : $part;
                    $lignes[] = ['type' => 'cheque', 'montant' => $m];
                }
                break;
            case 'cheque1':
                $lignes[] = ['type' => 'cheque', 'montant' => $montant];
                break;
            case 'carte':
                $lignes[] = ['type' => 'carte', 'montant' => $montant];
                break;
            case 'espece':
                $lignes[] = ['type' => 'espece', 'montant' => $montant];
                break;
            case 'mix_espece_cheque':
                $part = (int) round($montant * 0.4);
                if ($part > 0 && $montant - $part > 0) {
                    $lignes[] = ['type' => 'espece', 'montant' => $part];
                    $lignes[] = ['type' => 'cheque', 'montant' => $montant - $part];
                } else {
                    $lignes[] = ['type' => 'espece', 'montant' => $montant];
                }
                break;
            case 'mix_carte_espece':
                $part = (int) round($montant * 0.6);
                if ($part > 0 && $montant - $part > 0) {
                    $lignes[] = ['type' => 'carte', 'montant' => $part];
                    $lignes[] = ['type' => 'espece', 'montant' => $montant - $part];
                } else {
                    $lignes[] = ['type' => 'carte', 'montant' => $montant];
                }
                break;
        }

        $banqueFamille = $this->faker->randomElement(self::BANQUES);

        foreach ($lignes as $ligne) {
            $details = null;
            if ($ligne['type'] === 'cheque') {
                $details = [
                    'banque' => $banqueFamille,
                    'numero' => (string) $this->faker->numberBetween(1000000, 9999999),
                    'nom_emetteur' => $responsible->first_name . ' ' . $responsible->last_name,
                ];
            }

            LignePaiement::create([
                'paiement_id' => $paiement->id,
                'type_paiement' => $ligne['type'],
                'montant' => $ligne['montant'],
                'details' => $details,
                'created_by' => $director->id,
            ]);
        }
    }
}
