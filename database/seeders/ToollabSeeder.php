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
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ToollabSeeder extends Seeder
{
    private $faker;
    private $school;
    private $schoolYear;
    private $cursus;
    private $levels;
    private $classrooms = [];
    private $families = [];
    private $students = [];
    private $responsibles = [];
    private $teachers = [];

    public function __construct()
    {
        $this->faker = Faker::create('fr_FR');
    }

    public function run(): void
    {
        $this->createSchoolAndDirector();
        $this->createCursusWithTarifs();
        $this->createFamilyReductions();
        $this->createArabicClasses();
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

    private function createCursusWithTarifs(): void
    {
        $this->cursus = Cursus::create([
            'name' => 'Cursus Langue Arabe',
            'progression' => 'levels',
            'school_id' => $this->school->id,
        ]);

        $this->levels = collect();
        for ($i = 1; $i <= 5; $i++) {
            $level = CursusLevel::create([
                'cursus_id' => $this->cursus->id,
                'name' => "Niveau $i",
                'order' => $i,
            ]);
            $this->levels->push($level);
        }

        Tarif::create([
            'cursus_id' => $this->cursus->id,
            'prix' => 270,
            'actif' => true,
        ]);
    }

    private function createFamilyReductions(): void
    {
        $director = User::where('email', 'relhanti@gmail.com')->first();
        
        ReductionFamiliale::create([
            'cursus_id' => $this->cursus->id,
            'nombre_eleves_min' => 3,
            'pourcentage_reduction' => 11.11,
            'actif' => true,
            'created_by' => $director->id,
        ]);

        ReductionFamiliale::create([
            'cursus_id' => $this->cursus->id,
            'nombre_eleves_min' => 5,
            'pourcentage_reduction' => 22.22,
            'actif' => true,
            'created_by' => $director->id,
        ]);
    }

    private function createArabicClasses(): void
    {
        $classTypes = ['Enfants', 'Femmes', 'Hommes'];
        $classSizes = [15, 20, 25, 30];

        for ($i = 1; $i <= 20; $i++) {
            $level = $this->levels->random();
            $type = $this->faker->randomElement($classTypes);
            $size = $this->faker->randomElement($classSizes);
            
            $classroom = Classroom::create([
                'school_id' => $this->school->id,
                'name' => "Classe Arabe {$level->name} - {$type} {$i}",
                'years' => date('Y'),
                'type' => $type,
                'size' => $size,
                'cursus_id' => $this->cursus->id,
                'level_id' => $level->id,
                'gender' => $type,
            ]);
            
            $this->classrooms[] = $classroom;
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
            ['13:30', '15:30'],
            ['14:00', '16:00'],
            ['15:30', '17:30'],
            ['17:00', '19:00'],
        ];

        foreach ($this->classrooms as $classroom) {
            $nbSchedules = $this->faker->numberBetween(1, 3);
            $usedKeys = [];

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

                ClassSchedule::create([
                    'classroom_id' => $classroom->id,
                    'teacher_id' => $teacher->id,
                    'day' => $day,
                    'start_time' => $slot[0],
                    'end_time' => $slot[1],
                ]);
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
        $totalStudents = 0;
        
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
                $totalStudents++;
            }
        }
    }

    private function generateStudentDistribution(): array
    {
        $distribution = [];
        
        for ($i = 0; $i < 5; $i++) $distribution[] = 1;
        for ($i = 0; $i < 10; $i++) $distribution[] = 2;
        for ($i = 0; $i < 10; $i++) $distribution[] = 3;
        for ($i = 0; $i < 10; $i++) $distribution[] = 4;
        for ($i = 0; $i < 10; $i++) $distribution[] = 5;
        for ($i = 0; $i < 10; $i++) $distribution[] = 6;
        for ($i = 0; $i < 3; $i++) $distribution[] = 7;
        for ($i = 0; $i < 2; $i++) $distribution[] = 8;
        
        shuffle($distribution);
        return $distribution;
    }

    private function enrollStudentsInClasses(): void
    {
        $classroomFillRates = $this->generateClassroomFillRates();

        $byGender = ['Femmes' => [], 'Hommes' => [], 'Enfants' => []];
        $familyByStudent = [];
        foreach ($this->students as $student) {
            if ($student->is_child) {
                $byGender['Enfants'][] = $student;
            } elseif ($student->gender === 'F') {
                $byGender['Femmes'][] = $student;
            } elseif ($student->gender === 'M') {
                $byGender['Hommes'][] = $student;
            }
        }
        foreach ($this->families as $fId => $fData) {
            foreach ($fData['students'] as $s) {
                $familyByStudent[$s->id] = $fId;
            }
        }

        foreach ($this->classrooms as $index => $classroom) {
            $fillRate = $classroomFillRates[$index];
            $target = (int) ($classroom->size * $fillRate);
            if ($target <= 0) continue;

            $pool = $byGender[$classroom->gender] ?? [];
            if (empty($pool)) continue;

            shuffle($pool);
            $enrolled = 0;
            foreach ($pool as $student) {
                if ($enrolled >= $target) break;
                $familyId = $familyByStudent[$student->id] ?? null;
                if (!$familyId) continue;

                $exists = StudentClassroom::where('student_id', $student->id)
                    ->where('classroom_id', $classroom->id)
                    ->exists();
                if ($exists) continue;

                StudentClassroom::create([
                    'student_id' => $student->id,
                    'classroom_id' => $classroom->id,
                    'family_id' => $familyId,
                    'status' => 'active',
                    'enrollment_date' => Carbon::now()->subDays(rand(1, 30)),
                ]);
                $enrolled++;
            }
        }
    }

    private function generateClassroomFillRates(): array
    {
        $rates = [];
        
        for ($i = 0; $i < 3; $i++) {
            $rates[] = 1.0;
        }
        
        for ($i = 0; $i < 5; $i++) {
            $rates[] = $this->faker->randomFloat(2, 0.85, 0.95);
        }
        
        for ($i = 0; $i < 7; $i++) {
            $rates[] = $this->faker->randomFloat(2, 0.5, 0.8);
        }
        
        for ($i = 0; $i < 5; $i++) {
            $rates[] = $this->faker->randomFloat(2, 0.2, 0.4);
        }
        
        shuffle($rates);
        return $rates;
    }

    private function createPayments(): void
    {
        $director = User::where('email', 'relhanti@gmail.com')->first();
        
        foreach ($this->families as $familyData) {
            $family = $familyData['family'];
            
            // Count only enrolled students
            $enrolledStudents = StudentClassroom::where('family_id', $family->id)
                ->where('status', 'active')
                ->count();
            
            if ($enrolledStudents === 0) continue;
            
            $basePrice = 270;
            $totalAmount = $this->calculatePriceWithReduction($basePrice, $enrolledStudents);
            
            // Determine if this family pays or not
            $shouldPay = $this->faker->randomElement([true, true, true, true, false]); // 80% pay
            
            if (!$shouldPay) continue;
            
            $paiement = Paiement::create([
                'family_id' => $family->id,
                'created_by' => $director->id,
            ]);
            
            // Some families get exonerations
            $hasExoneration = $this->faker->randomElement([false, false, false, false, false, false, false, false, false, true]); // 10% exoneration
            
            if ($hasExoneration) {
                LignePaiement::create([
                    'paiement_id' => $paiement->id,
                    'type_paiement' => 'exoneration',
                    'montant' => $totalAmount,
                    'details' => [
                        'motif' => $this->faker->randomElement(['Situation sociale', 'Bourse', 'Personnel institut']),
                    ],
                    'created_by' => $director->id,
                ]);
            } else {
                // Partial payment
                $paymentPercentage = $this->faker->randomElement([1, 1, 1, 0.5, 0.75]); // Most pay full, some partial
                $amountToPay = round($totalAmount * $paymentPercentage, 2);
                $remainingAmount = $amountToPay;
                $paymentCount = $this->faker->randomElement([1, 2, 3]);
                
                for ($i = 0; $i < $paymentCount; $i++) {
                    $isLastPayment = ($i === $paymentCount - 1);
                    $amount = $isLastPayment ? $remainingAmount : round($amountToPay / $paymentCount, 2);
                    
                    $paymentMethod = $this->faker->randomElement([
                        'cheque', 'cheque', 'cheque', 'cheque', 'cheque', 'cheque',
                        'espece', 'espece', 'espece',
                        'carte'
                    ]);
                    
                    $details = null;
                    if ($paymentMethod === 'cheque') {
                        $responsible = $familyData['responsible'];
                        $details = [
                            'numero' => (string)rand(1000000, 9999999),
                            'banque' => $this->faker->randomElement(['BNP Paribas', 'Crédit Agricole', 'Société Générale', 'LCL']),
                            'date' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d'),
                            'emetteur' => $responsible->first_name . ' ' . $responsible->last_name,
                        ];
                    }
                    
                    LignePaiement::create([
                        'paiement_id' => $paiement->id,
                        'type_paiement' => $paymentMethod,
                        'montant' => $amount,
                        'details' => $details,
                        'created_by' => $director->id,
                    ]);
                    
                    $remainingAmount -= $amount;
                }
            }
        }
    }

    private function calculatePriceWithReduction($basePrice, $numberOfChildren): float
    {
        if ($numberOfChildren >= 5) {
            return 210 * $numberOfChildren;
        } elseif ($numberOfChildren >= 3) {
            return 240 * $numberOfChildren;
        } else {
            return 270 * $numberOfChildren;
        }
    }
}