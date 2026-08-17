<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Comment;
use App\Models\Cursus;
use App\Models\CursusLevel;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Models\Paiement;
use App\Models\ReductionFamiliale;
use App\Models\ReductionMultiCursus;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\StudentYearOutcome;
use App\Models\Tarif;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserRole;
use App\Services\TarifCalculatorService;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Jeu de démo MULTI-ÉCOLES et MULTI-ANNÉES, cohérent de bout en bout.
 *
 * Ce que ni ToollabSeeder ni AlQalamSeeder ne produisent : plusieurs écoles
 * peuplées en parallèle, et des années scolaires DÉJÀ CLÔTURÉES. Sans ça, tout
 * le code d'archive, de lecture seule, de reconduction, de décisions et
 * d'émargement n'a jamais de données pour tourner.
 *
 * SIMULATION, PAS TIRAGE AU SORT. Les trois années ne sont pas peuplées
 * indépendamment : on rejoue la scolarité de chaque élève année après année.
 * La décision de fin d'année pilote l'inscription de l'année suivante —
 * passage monte d'un niveau, redoublement reste, exclusion et fin de cursus
 * sortent l'élève. Un directeur qui ouvre une archive doit y retrouver une
 * histoire qui se tient.
 *
 * Les invariants respectés, parce que l'application les impose :
 *   - un élève ne va que dans une classe compatible avec son profil
 *     (enfant → Enfants, adulte → Hommes/Femmes) ;
 *   - la capacité d'une classe n'est jamais dépassée ;
 *   - un cursus = une seule classe par élève et par année ;
 *   - chaque inscription porte son tarif_snapshot, comme le fait
 *     StudentClassroomController::enroll — sinon les montants ne sont pas ceux
 *     d'un vrai dossier ;
 *   - `passage` et `redoublement` sont interdits en cursus continu ;
 *   - émargement et décisions n'existent que pour des élèves réellement
 *     inscrits cette année-là.
 *
 * Volontairement séparé de DatabaseSeeder : `migrate:fresh --seed` continue de
 * produire exactement le jeu Al-Hikma que l'équipe connaît.
 *
 *   docker exec api_dev_toollab php artisan db:seed --class=DemoSeeder --force
 */
class DemoSeeder extends Seeder
{
    private const ECOLES = [
        [
            'name' => 'Institut An-Nour',
            'email' => 'contact@an-nour.fr',
            'domain' => 'an-nour.fr',
            'address' => '12 rue Paul Bert',
            'zipcode' => '69003',
            'city' => 'Lyon',
            'staff' => [
                'director' => ['Yacine', 'Belkacem'],
                'admin' => ['Nadia', 'Cherif'],
                'registar' => ['Sofiane', 'Merbah'],
            ],
            'teachers' => [['Amina', 'Boudjema'], ['Karim', 'Slimani'], ['Leila', 'Mansouri']],
            // Saisie des décisions ouverte ici, fermée à Al-Fajr : les deux
            // états du toggle sont observables sans rien basculer.
            'outcomes_open' => true,
            'invite' => ['Yasmine', 'Draoui'],
        ],
        [
            'name' => 'Centre Al-Fajr',
            'email' => 'contact@al-fajr.fr',
            'domain' => 'al-fajr.fr',
            'address' => '5 boulevard National',
            'zipcode' => '13003',
            'city' => 'Marseille',
            'staff' => [
                'director' => ['Rachid', 'Toumi'],
                'admin' => ['Samia', 'Hadj'],
                'registar' => ['Malik', 'Ferhat'],
            ],
            'teachers' => [['Ines', 'Ouali'], ['Bilal', 'Rahmani'], ['Nour', 'Kaddour']],
            'outcomes_open' => false,
            'invite' => ['Omar', 'Zerrouki'],
        ],
    ];

    private const BANQUES = ['BNP Paribas', 'Crédit Agricole', 'Société Générale', 'LCL', 'CIC'];

    private const NIVEAUX = ['1ère année', '2ème année', '3ème année'];

    private $faker;

    private TarifCalculatorService $calculator;

    /** Domaine e-mail de l'école en cours de création. */
    private string $domaine = '';

    /**
     * Compteur global : garantit des e-mails uniques même si la base contient
     * déjà les jeux Al-Hikma ou Al Qalam. faker->unique() ne connaît que sa
     * propre session et entre en collision avec les safeEmail() existants.
     */
    private int $compteur = 0;

    /** Places restantes par classe, pour ne jamais dépasser la capacité. */
    private array $placesRestantes = [];

    public function __construct()
    {
        $this->faker = Faker::create('fr_FR');
        $this->calculator = new TarifCalculatorService();
    }

    public function run(): void
    {
        // Ce seeder EFFACE des données. Il ne doit jamais s'exécuter ailleurs
        // qu'en local ou en test, quelles que soient les options passées.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('DemoSeeder est réservé au développement (APP_ENV='.app()->environment().'). Abandon.');

            return;
        }

        $this->call(RoleSeeder::class);

        // Le seeder est REJOUABLE : il efface d'abord ses propres écoles, puis
        // les recrée. Un simple « skip si elle existe » interdirait d'enrichir
        // le jeu de données sans repartir d'une base neuve, et un run
        // interrompu laisserait une école à moitié peuplée.
        $this->purgerEcolesDemo();

        foreach (self::ECOLES as $config) {
            DB::transaction(fn () => $this->seedEcole($config));
            $this->command?->info($config['name'].' créée.');
        }

        $this->restaurerContexte(null, null);
        $this->recapitulatif();
    }

    /**
     * Efface intégralement les écoles de ce seeder — et rien d'autre.
     *
     * Requêtes SQL brutes volontairement : les modèles portent des global
     * scopes (école, année, soft delete conscient de l'année) qui masqueraient
     * une partie des lignes à supprimer et laisseraient des orphelins.
     * L'ordre suit les dépendances, des feuilles vers les racines.
     */
    private function purgerEcolesDemo(): void
    {
        $ecoles = collect();

        foreach (self::ECOLES as $config) {
            foreach (DB::table('schools')->where('email', $config['email'])->get() as $ecole) {
                // Triple vérification avant d'effacer quoi que ce soit. Une
                // école cliente réelle porte un SIRET (Al Qalam par exemple) ;
                // les écoles de démo n'en ont jamais. Si le nom ne correspond
                // pas non plus, c'est que l'adresse a été réutilisée par
                // quelqu'un d'autre : on refuse et on le dit.
                if ($ecole->siret !== null || $ecole->name !== $config['name']) {
                    $this->command?->error(
                        'REFUS : « '.$ecole->name.' » ('.$ecole->email.') porte l\'adresse d\'une école '
                        .'de démo mais n\'en est pas une. Rien n\'a été supprimé.'
                    );

                    throw new \RuntimeException('DemoSeeder : école inattendue sur '.$config['email']);
                }

                $ecoles->push($ecole->id);
            }
        }

        if ($ecoles->isEmpty()) {
            return;
        }

        $familles = DB::table('families')->whereIn('school_id', $ecoles)->pluck('id');
        $classes = DB::table('classrooms')->whereIn('school_id', $ecoles)->pluck('id');
        $cursus = DB::table('cursus')->whereIn('school_id', $ecoles)->pluck('id');
        $paiements = DB::table('paiements')->whereIn('family_id', $familles)->pluck('id');

        // Les comptes à supprimer sont identifiés par leurs rattachements, pas
        // par leur e-mail : un membre peut avoir été créé avec une adresse
        // aléatoire par une version antérieure du seeder.
        $membres = DB::table('user_roles')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('roleable_type', 'school')->whereIn('roleable_id', $ecoles))
                ->orWhere(fn ($w) => $w->where('roleable_type', 'family')->whereIn('roleable_id', $familles))
                ->orWhere(fn ($w) => $w->where('roleable_type', 'classroom')->whereIn('roleable_id', $classes)))
            ->pluck('user_id')
            ->unique();

        DB::transaction(function () use ($ecoles, $familles, $classes, $cursus, $paiements, $membres) {
            DB::table('lignes_paiement')->whereIn('paiement_id', $paiements)->delete();
            DB::table('paiements')->whereIn('id', $paiements)->delete();
            DB::table('attendances')->whereIn('school_id', $ecoles)->delete();
            DB::table('student_year_outcomes')->whereIn('classroom_id', $classes)->delete();
            DB::table('student_classrooms')->whereIn('family_id', $familles)->delete();
            DB::table('class_schedules')->whereIn('classroom_id', $classes)->delete();
            DB::table('comments')->whereIn('family_id', $familles)->delete();

            DB::table('user_roles')
                ->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('roleable_type', 'school')->whereIn('roleable_id', $ecoles))
                    ->orWhere(fn ($w) => $w->where('roleable_type', 'family')->whereIn('roleable_id', $familles))
                    ->orWhere(fn ($w) => $w->where('roleable_type', 'classroom')->whereIn('roleable_id', $classes)))
                ->delete();

            // main_teacher_id référence un user : on le libère avant de toucher
            // aux comptes, sinon la contrainte de clé étrangère bloque.
            DB::table('classrooms')->whereIn('id', $classes)->update(['main_teacher_id' => null]);

            // Un compte partagé avec une AUTRE école garde au moins un rôle :
            // on ne le supprime pas.
            $orphelins = $membres->diff(DB::table('user_roles')->whereIn('user_id', $membres)->pluck('user_id'));
            DB::table('user_infos')->whereIn('user_id', $orphelins)->delete();
            DB::table('users')->whereIn('id', $orphelins)->delete();

            DB::table('classrooms')->whereIn('id', $classes)->delete();
            DB::table('tarifs')->whereIn('cursus_id', $cursus)->delete();
            DB::table('reduction_familiales')->whereIn('cursus_id', $cursus)->delete();
            DB::table('reduction_multi_cursuses')->whereIn('cursus_beneficiaire_id', $cursus)->delete();
            DB::table('cursus_levels')->whereIn('cursus_id', $cursus)->delete();
            DB::table('cursus')->whereIn('id', $cursus)->delete();
            DB::table('families')->whereIn('id', $familles)->delete();
            DB::table('invitation_tokens')->whereIn('school_id', $ecoles)->delete();
            DB::table('school_years')->whereIn('school_id', $ecoles)->delete();
            DB::table('schools')->whereIn('id', $ecoles)->delete();
        });

        $this->command?->warn('Écoles de démo précédentes effacées ('.$ecoles->count().').');
    }

    private function seedEcole(array $config): void
    {
        $school = School::create([
            'name' => $config['name'],
            'email' => $config['email'],
            'address' => $config['address'],
            'zipcode' => $config['zipcode'],
            'city' => $config['city'],
            'country' => 'France',
            'access' => true,
        ]);

        // Obligatoire avant toute écriture scopée : hors HTTP, currentSchoolId()
        // est nul et le scope école fail-closed renverrait 0 ligne.
        $this->restaurerContexte($school->id, null);
        $this->domaine = $config['domain'];
        $this->placesRestantes = [];

        $staff = $this->creerStaff($school, $config);
        $annees = $this->creerAnnees($school);
        $cursus = $this->creerCursus();
        $familles = $this->creerFamilles();

        if ($config['outcomes_open']) {
            $annees[2]->update(['outcomes_open' => true]);
        }

        // Tarification et classes de chaque année AVANT la simulation : elle a
        // besoin de connaître les classes des trois années pour y faire
        // progresser les élèves.
        $classesParAnnee = [];
        foreach ($annees as $rang => $annee) {
            $this->restaurerContexte($school->id, $annee->id);
            $this->creerTarification($cursus, $rang);
            $classesParAnnee[$rang] = $this->creerClasses($annee, $cursus, $staff['teachers']);
        }

        $this->simulerScolarite($school, $annees, $classesParAnnee, $familles, $staff);

        $this->restaurerContexte($school->id, $annees[2]->id);
        $this->creerCommentaires($familles, $staff);
        $this->supprimerQuelquesFamilles($annees, $familles);
    }

    /* ---------------------------------------------------------------------
     | Structure permanente : staff, années, cursus, familles
     |--------------------------------------------------------------------- */

    private function creerStaff(School $school, array $config): array
    {
        $staff = [];

        foreach ($config['staff'] as $slug => [$prenom, $nom]) {
            $staff[$slug] = $this->creerCompte($prenom, $nom);
            $this->rattacherRole($staff[$slug], $slug, 'school', $school->id);
        }

        $staff['teachers'] = [];
        foreach ($config['teachers'] as [$prenom, $nom]) {
            $prof = $this->creerCompte($prenom, $nom);
            $this->rattacherRole($prof, 'teacher', 'school', $school->id);
            $staff['teachers'][] = $prof;
        }

        // Adhésion NON acceptée : l'école ne doit voir que son e-mail et le
        // badge « En attente d'acceptation », jamais son nom. Sans ce cas, tout
        // le mécanisme accepted_at est invisible dans les données de démo.
        [$prenom, $nom] = $config['invite'];
        $invite = $this->creerCompte($prenom, $nom);
        UserRole::create([
            'user_id' => $invite->id,
            'role_id' => $this->roleId('registar'),
            'roleable_type' => 'school',
            'roleable_id' => $school->id,
            'accepted_at' => null,
        ]);

        return $staff;
    }

    /**
     * 3 années : N-2 et N-1 clôturées, N active.
     *
     * Elles sont créées directement dans leur état final. Le refus d'écriture
     * sur une année close vit dans le middleware SchoolYearContext, pas dans le
     * modèle : un seeder peut donc peupler une année déjà fermée.
     */
    private function creerAnnees(School $school): array
    {
        $rentree = Carbon::now()->month >= 9 ? Carbon::now()->year : Carbon::now()->year - 1;
        $annees = [];

        foreach ([2, 1, 0] as $recul) {
            $debut = $rentree - $recul;
            $close = $recul > 0;

            $annees[] = SchoolYear::create([
                'label' => $debut.'-'.($debut + 1),
                'opened_at' => Carbon::create($debut, 9, 1, 8, 0),
                'closed_at' => $close ? Carbon::create($debut + 1, 7, 15, 18, 0) : null,
                'is_active' => ! $close,
                'outcomes_open' => false,
            ]);
        }

        return $annees;
    }

    /**
     * Les cursus sont PERMANENTS (school-scoped, pas year-scoped) : créés une
     * fois, ils traversent les trois années. Seuls leurs tarifs sont refaits
     * chaque année.
     */
    private function creerCursus(): array
    {
        $arabe = Cursus::create(['name' => 'Arabe', 'progression' => 'levels']);

        $niveaux = collect(self::NIVEAUX)->map(fn (string $nom, int $i) => CursusLevel::create([
            'cursus_id' => $arabe->id,
            'name' => $nom,
            'order' => $i + 1,
        ]));

        $coran = Cursus::create(['name' => 'Coran', 'progression' => 'continu']);

        return ['arabe' => $arabe, 'niveaux' => $niveaux, 'coran' => $coran];
    }

    /** Les tarifs montent d'une année sur l'autre : le rejeu annuel se voit. */
    private function creerTarification(array $cursus, int $rang): void
    {
        Tarif::create([
            'cursus_id' => $cursus['arabe']->id,
            'prix' => [240, 255, 270][$rang],
            'actif' => true,
        ]);
        Tarif::create([
            'cursus_id' => $cursus['coran']->id,
            'prix' => [130, 140, 150][$rang],
            'actif' => true,
        ]);

        ReductionFamiliale::create([
            'cursus_id' => $cursus['arabe']->id,
            'nombre_eleves_min' => 3,
            'pourcentage_reduction' => 15.00,
            'actif' => true,
        ]);

        ReductionMultiCursus::create([
            'cursus_beneficiaire_id' => $cursus['coran']->id,
            'cursus_requis_id' => $cursus['arabe']->id,
            'pourcentage_reduction' => 50.00,
            'actif' => true,
        ]);
    }

    /**
     * Familles permanentes, avec un profil figé par élève.
     *
     * Le profil (enfant / adulte, genre) est décidé ici une fois pour toutes :
     * c'est lui qui déterminera dans quelles classes l'élève peut aller, sur
     * les trois années. Un élève qui changerait de profil d'une année à
     * l'autre produirait exactement l'incohérence qu'on veut éviter.
     */
    private function creerFamilles(): array
    {
        $familles = [];

        for ($i = 0; $i < 20; $i++) {
            $famille = Family::create([]);
            $nomFamille = $this->faker->unique()->lastName();

            $responsables = [$this->creerMembre($famille, 'responsible', $nomFamille, 'adulte')];
            if ($i % 3 === 0) {
                $responsables[] = $this->creerMembre($famille, 'responsible', $nomFamille, 'adulte');
            }

            $eleves = [];
            foreach (range(1, $this->faker->numberBetween(1, 4)) as $ignored) {
                // 3 élèves sur 4 sont des enfants ; le reste des adultes, qui
                // ne suivent que le Coran (classes Hommes / Femmes). En dessous
                // de ce ratio, les deux classes adultes restent vides.
                $profil = $this->faker->numberBetween(1, 4) === 1 ? 'adulte' : 'enfant';
                $eleve = $this->creerMembre($famille, 'student', $nomFamille, $profil);
                $eleves[] = ['user' => $eleve, 'profil' => $profil, 'genre' => $this->genreDe($eleve)];
            }

            $familles[] = [
                'family' => $famille,
                'nom' => $nomFamille,
                'responsables' => $responsables,
                'eleves' => $eleves,
                // 0 = présente dès N-2, 1 = arrive en N-1, 2 = arrive en N.
                'arrivee' => $i < 10 ? 0 : ($i < 15 ? 1 : 2),
            ];
        }

        return $familles;
    }

    /* ---------------------------------------------------------------------
     | Classes
     |--------------------------------------------------------------------- */

    /**
     * 9 classes par année, dont le genre couvre réellement la population.
     *
     * L'Arabe est un cursus à niveaux suivi par les enfants : deux classes
     * `Enfants` par niveau. Le Coran est continu et mélange les publics : une
     * classe `Enfants`, une `Femmes`, une `Hommes`. Sans classe pour adultes,
     * un élève majeur n'aurait aucune place légitime — c'était le défaut de la
     * version précédente.
     */
    private function creerClasses(SchoolYear $annee, array $cursus, array $profs): array
    {
        $annuaire = ['arabe' => [], 'coran' => []];
        $courts = ['1ère', '2ème', '3ème'];

        foreach ($cursus['niveaux'] as $i => $niveau) {
            foreach (['A', 'B'] as $lettre) {
                $annuaire['arabe'][$i][] = $this->creerClasse(
                    $courts[$i].' '.$lettre, 'Arabe', $cursus['arabe']->id,
                    $niveau->id, 'Enfants', $annee, $profs
                );
            }
        }

        foreach (['Enfants', 'Femmes', 'Hommes'] as $genre) {
            $annuaire['coran'][$genre] = $this->creerClasse(
                'Coran '.$genre, 'Coran', $cursus['coran']->id,
                null, $genre, $annee, $profs
            );
        }

        return $annuaire;
    }

    private function creerClasse(
        string $nom,
        string $type,
        int $cursusId,
        ?int $levelId,
        string $genre,
        SchoolYear $annee,
        array $profs
    ): Classroom {
        $classe = Classroom::create([
            'name' => $nom,
            'years' => (int) substr($annee->label, 0, 4),
            'type' => $type,
            'size' => 16,
            'cursus_id' => $cursusId,
            'level_id' => $levelId,
            'gender' => $genre,
        ]);

        $prof = $this->faker->randomElement($profs);

        ClassSchedule::create([
            'classroom_id' => $classe->id,
            'teacher_id' => $prof->id,
            'day' => $this->faker->randomElement(['Samedi', 'Dimanche', 'Mercredi']),
            'start_time' => $this->faker->randomElement(['09:00', '11:00', '14:00']),
            'end_time' => $this->faker->randomElement(['11:00', '13:00', '16:00']),
        ]);

        // main_teacher_id n'est pas fillable (il est piloté par syncMainTeacher
        // côté contrôleur) : on l'assigne directement.
        $classe->main_teacher_id = $prof->id;
        $classe->save();

        $this->placesRestantes[$classe->id] = (int) $classe->size;

        return $classe;
    }

    /* ---------------------------------------------------------------------
     | La simulation : trois années enchaînées
     |--------------------------------------------------------------------- */

    /**
     * Rejoue la scolarité année par année.
     *
     * L'état de chaque élève (niveau d'Arabe atteint, Coran suivi ou non,
     * sorti de l'école) est porté d'une année sur l'autre par les décisions de
     * fin d'année. C'est ce qui rend l'archive crédible : un élève « passage »
     * en 1ère année se retrouve en 2ème l'année suivante, un « redoublement »
     * reste, un « exclusion » ou « fin de cursus » n'y est plus.
     */
    private function simulerScolarite(
        School $school,
        array $annees,
        array $classesParAnnee,
        array $familles,
        array $staff
    ): void {
        $etats = [];

        foreach ($familles as $donnees) {
            foreach ($donnees['eleves'] as $eleve) {
                $etats[$eleve['user']->id] = [
                    // Les adultes ne suivent pas l'Arabe à niveaux.
                    //
                    // Une famille présente dès la première année a des enfants
                    // répartis sur les trois niveaux — sinon la 3ème année
                    // resterait vide deux ans. Une famille qui arrive ensuite
                    // inscrit en 1ère année : c'est le recrutement de la
                    // rentrée, et c'est lui qui repeuple les petits niveaux au
                    // fur et à mesure que les anciens terminent leur cursus.
                    'niveauArabe' => $eleve['profil'] === 'adulte'
                        ? null
                        : ($donnees['arrivee'] > 0
                            ? 0
                            : $this->faker->numberBetween(0, count(self::NIVEAUX) - 1)),
                    // 1 enfant sur 3 suit aussi le Coran ; les adultes n'ont que lui.
                    'coran' => $eleve['profil'] === 'adulte' || $this->faker->numberBetween(1, 3) === 1,
                    'sorti' => false,
                ];
            }
        }

        foreach ($annees as $rang => $annee) {
            $this->restaurerContexte($school->id, $annee->id);
            $classes = $classesParAnnee[$rang];

            foreach ($familles as $donnees) {
                if ($donnees['arrivee'] > $rang) {
                    continue;
                }

                foreach ($donnees['eleves'] as $eleve) {
                    $this->inscrireSelonEtat($eleve, $etats[$eleve['user']->id], $donnees['family'], $classes, $annee);
                }

                $this->regler($donnees, $staff['director'], $annee);
            }

            $this->creerEmargement($annee, $classes);

            // Les décisions ne se prennent qu'en fin d'année : l'année en cours
            // n'en a pas — c'est justement ce qu'on va y saisir à la main.
            if ($annee->closed_at !== null) {
                $this->deciderEtFaireProgresser($annee, $classes, $familles, $etats, $rang);
            }
        }
    }

    /** Inscrit l'élève là où son état de scolarité le place. */
    private function inscrireSelonEtat(array $eleve, array $etat, Family $famille, array $classes, SchoolYear $annee): void
    {
        if ($etat['sorti']) {
            return;
        }

        $date = $annee->opened_at->copy()->addDays($this->faker->numberBetween(1, 30));

        if ($etat['niveauArabe'] !== null) {
            $classe = $this->classeDisponible($classes['arabe'][$etat['niveauArabe']] ?? []);
            if ($classe) {
                $this->creerInscription($eleve['user'], $classe, $famille, $date);
            }
        }

        if ($etat['coran']) {
            $classe = $this->classeDisponible([$classes['coran'][$this->genreDeClasse($eleve)]]);
            if ($classe) {
                $this->creerInscription($eleve['user'], $classe, $famille, $date);
            }
        }
    }

    /** Le genre de classe compatible avec le profil de l'élève. */
    private function genreDeClasse(array $eleve): string
    {
        if ($eleve['profil'] === 'enfant') {
            return 'Enfants';
        }

        return $eleve['genre'] === 'F' ? 'Femmes' : 'Hommes';
    }

    /**
     * La classe la MOINS remplie parmi les candidates.
     *
     * Prendre la première disponible remplissait « 1ère A » jusqu'à saturation
     * avant de toucher à « 1ère B », qui restait vide. Une école répartit ses
     * élèves entre les groupes d'un même niveau.
     */
    private function classeDisponible(array $candidates): ?Classroom
    {
        $meilleure = null;
        $meilleurRestant = 0;

        foreach ($candidates as $classe) {
            $restant = $this->placesRestantes[$classe->id] ?? 0;

            if ($restant > $meilleurRestant) {
                $meilleure = $classe;
                $meilleurRestant = $restant;
            }
        }

        return $meilleure;
    }

    private function creerInscription(User $eleve, Classroom $classe, Family $famille, Carbon $date): void
    {
        StudentClassroom::create([
            'student_id' => $eleve->id,
            'classroom_id' => $classe->id,
            'family_id' => $famille->id,
            'status' => 'active',
            'enrollment_date' => $date,
            // Le tarif est figé à l'inscription, exactement comme le fait
            // StudentClassroomController::enroll. Sans lui, les montants sont
            // recalculés au tarif courant : ce ne sont plus ceux du dossier.
            'tarif_snapshot' => $this->snapshotTarifaire($classe),
        ]);

        UserRole::create([
            'user_id' => $eleve->id,
            'role_id' => $this->roleId('student'),
            'roleable_type' => 'classroom',
            'roleable_id' => $classe->id,
        ]);

        $this->placesRestantes[$classe->id]--;
    }

    /** Réplique de StudentClassroomController::buildTarifSnapshot(). */
    private function snapshotTarifaire(Classroom $classe): array
    {
        $cursus = $classe->cursus()
            ->with(['tarif', 'reductionsFamiliales', 'reductionsMultiCursusBeneficiaire'])
            ->first();

        if (! $cursus) {
            return [];
        }

        return [
            'cursus_id' => $cursus->id,
            'school_year_id' => $classe->school_year_id,
            'tarif_base' => $cursus->tarif ? (int) $cursus->tarif->prix : null,
            'reductions_familiales' => $cursus->reductionsFamiliales->map(fn ($r) => [
                'nombre_eleves_min' => (int) $r->nombre_eleves_min,
                'pourcentage_reduction' => (float) $r->pourcentage_reduction,
            ])->values()->all(),
            'reductions_multi_cursus' => $cursus->reductionsMultiCursusBeneficiaire->map(fn ($r) => [
                'cursus_requis_id' => (int) $r->cursus_requis_id,
                'pourcentage_reduction' => (float) $r->pourcentage_reduction,
            ])->values()->all(),
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    /* ---------------------------------------------------------------------
     | Décisions de fin d'année, et ce qu'elles entraînent
     |--------------------------------------------------------------------- */

    /**
     * Décide pour chaque inscription de l'année close, puis reporte la décision
     * sur l'état de l'élève pour l'année suivante.
     *
     * Deux règles du domaine, sans quoi le jeu serait refusé par l'application
     * elle-même :
     *   - un cursus `continu` (Coran) interdit `passage` et `redoublement` ;
     *     un élève qui poursuit n'a donc AUCUNE décision ;
     *   - la décision est portée par la CLASSE, pas par le cursus.
     *
     * La dernière année close est laissée partiellement décidée : la vue
     * /decisions doit afficher un taux de complétion crédible.
     */
    private function deciderEtFaireProgresser(
        SchoolYear $annee,
        array $classes,
        array $familles,
        array &$etats,
        int $rang
    ): void {
        $complet = $rang === 0;
        $dernierNiveau = count(self::NIVEAUX) - 1;

        foreach ($familles as $donnees) {
            foreach ($donnees['eleves'] as $eleve) {
                $id = $eleve['user']->id;

                if ($etats[$id]['sorti']) {
                    continue;
                }

                // --- Arabe : cursus à niveaux ---
                if ($etats[$id]['niveauArabe'] !== null) {
                    $classe = $this->classeDeLEleve(
                        $eleve['user'],
                        $classes['arabe'][$etats[$id]['niveauArabe']] ?? []
                    );

                    if ($classe) {
                        $decision = $this->tirerDecisionArabe($etats[$id]['niveauArabe'], $dernierNiveau);

                        if ($complet || $this->faker->numberBetween(1, 100) > 25) {
                            $this->enregistrerDecision($eleve['user'], $annee, $classe, $decision);
                        }

                        match ($decision) {
                            'passage' => $etats[$id]['niveauArabe']++,
                            'redoublement' => null,
                            'fin_cursus' => $etats[$id]['niveauArabe'] = null,
                            'exclusion' => $etats[$id]['sorti'] = true,
                        };
                    }
                }

                if ($etats[$id]['sorti']) {
                    continue;
                }

                // --- Coran : cursus continu ---
                if ($etats[$id]['coran']) {
                    $classe = $this->classeDeLEleve($eleve['user'], [$classes['coran'][$this->genreDeClasse($eleve)]]);

                    // 4 élèves sur 5 poursuivent : aucune décision à saisir.
                    if ($classe && $this->faker->numberBetween(1, 5) === 1) {
                        $decision = $this->faker->randomElement(['fin_cursus', 'fin_cursus', 'exclusion']);
                        $this->enregistrerDecision($eleve['user'], $annee, $classe, $decision);

                        if ($decision === 'exclusion') {
                            $etats[$id]['sorti'] = true;
                        } else {
                            $etats[$id]['coran'] = false;
                        }
                    }
                }

                // Plus rien à suivre : l'élève quitte l'école.
                if ($etats[$id]['niveauArabe'] === null && ! $etats[$id]['coran']) {
                    $etats[$id]['sorti'] = true;
                }
            }
        }
    }

    private function tirerDecisionArabe(int $niveau, int $dernierNiveau): string
    {
        // Au dernier niveau, « passage » n'a pas de sens : le cursus s'achève.
        if ($niveau >= $dernierNiveau) {
            return $this->faker->numberBetween(1, 10) === 1 ? 'redoublement' : 'fin_cursus';
        }

        return $this->faker->randomElement([
            'passage', 'passage', 'passage', 'passage', 'passage', 'passage', 'passage',
            'redoublement', 'redoublement',
            'exclusion',
        ]);
    }

    /** La classe de cette liste dans laquelle l'élève est réellement inscrit. */
    private function classeDeLEleve(User $eleve, array $candidates): ?Classroom
    {
        foreach ($candidates as $classe) {
            $inscrit = StudentClassroom::where('student_id', $eleve->id)
                ->where('classroom_id', $classe->id)
                ->where('status', 'active')
                ->exists();

            if ($inscrit) {
                return $classe;
            }
        }

        return null;
    }

    private function enregistrerDecision(User $eleve, SchoolYear $annee, Classroom $classe, string $decision): void
    {
        StudentYearOutcome::create([
            'student_id' => $eleve->id,
            'school_year_id' => $annee->id,
            'classroom_id' => $classe->id,
            'outcome' => $decision,
            'commentaire' => $this->commentaireDecision($decision),
            'decided_by' => $classe->main_teacher_id,
            'decided_at' => $annee->closed_at->copy()->subDays($this->faker->numberBetween(3, 30)),
        ]);
    }

    private function commentaireDecision(string $decision): ?string
    {
        // Toutes les décisions ne sont pas commentées : la colonne Note doit
        // avoir des trous, sinon on ne voit jamais le cas vide.
        if ($this->faker->numberBetween(1, 100) > 45) {
            return null;
        }

        return $this->faker->randomElement(match ($decision) {
            'passage' => ['Très bon niveau', 'Progression régulière', 'Passage sans réserve'],
            'redoublement' => ['Bases à consolider', 'Absences trop nombreuses', 'Niveau insuffisant en lecture'],
            'exclusion' => ['Comportement répété malgré les avertissements', 'Absentéisme non justifié'],
            'fin_cursus' => ['Cursus terminé', 'A validé le dernier niveau', 'Départ de la famille'],
        });
    }

    /* ---------------------------------------------------------------------
     | Émargement
     |--------------------------------------------------------------------- */

    /**
     * Une ligne par élève, par classe et par date, pour les seuls inscrits.
     *
     * Séances hebdomadaires à partir de l'ouverture de l'année, arrêtées à
     * aujourd'hui pour l'année en cours — un émargement dans le futur n'aurait
     * aucun sens à l'écran.
     */
    private function creerEmargement(SchoolYear $annee, array $classes): void
    {
        $fin = $annee->closed_at ?? Carbon::now();

        $toutes = array_merge(
            array_merge(...array_values($classes['arabe'])),
            array_values($classes['coran'])
        );

        foreach ($toutes as $classe) {
            $inscriptions = StudentClassroom::where('classroom_id', $classe->id)
                ->where('status', 'active')
                ->get(['student_id']);

            if ($inscriptions->isEmpty()) {
                continue;
            }

            $date = $annee->opened_at->copy()->next(Carbon::SATURDAY);

            for ($seance = 0; $seance < 8; $seance++) {
                if ($date->greaterThan($fin)) {
                    break;
                }

                foreach ($inscriptions as $inscription) {
                    $tirage = $this->faker->numberBetween(1, 100);
                    $statut = match (true) {
                        $tirage <= 85 => 'present',
                        $tirage <= 93 => 'absent_justifie',
                        default => 'absent_non_justifie',
                    };

                    Attendance::create([
                        'student_id' => $inscription->student_id,
                        'classroom_id' => $classe->id,
                        'date' => $date->toDateString(),
                        'status' => $statut,
                        // La justification n'est conservée que sur une absence
                        // justifiée — même règle que l'API.
                        'justification' => $statut === 'absent_justifie'
                            ? $this->faker->randomElement(['Maladie', 'Rendez-vous médical', 'Voyage familial', 'Certificat fourni'])
                            : null,
                    ]);
                }

                $date->addWeek();
            }
        }
    }

    /* ---------------------------------------------------------------------
     | Argent
     |--------------------------------------------------------------------- */

    /**
     * Le montant dû est demandé au TarifCalculatorService plutôt que recalculé
     * en dur — ToollabSeeder duplique la grille tarifaire et se désynchronise
     * dès qu'un prix bouge (voir la skill seeders-donnees-test).
     */
    private function regler(array $donnees, User $director, SchoolYear $annee): void
    {
        $du = (int) round($this->calculator->calculerTotalFamille($donnees['family'])['total']);

        if ($du <= 0) {
            return;
        }

        $scenario = $this->faker->randomElement([
            'complet', 'complet', 'complet',
            'partiel', 'partiel',
            'rien',
            'exoneration',
        ]);

        // Une année close est forcément soldée : on ne laisse pas traîner des
        // impayés dans un exercice terminé.
        if ($annee->closed_at !== null && $scenario === 'rien') {
            $scenario = 'complet';
        }

        if ($scenario === 'rien') {
            return;
        }

        $paiement = Paiement::create([
            'family_id' => $donnees['family']->id,
            'created_by' => $director->id,
        ]);

        if ($scenario === 'exoneration') {
            LignePaiement::create([
                'paiement_id' => $paiement->id,
                'type_paiement' => 'exoneration',
                'montant' => $du,
                'details' => ['justification' => $this->faker->randomElement([
                    'Situation familiale difficile',
                    'Bourse accordée par le conseil',
                    'Famille de bénévoles',
                ])],
            ]);

            return;
        }

        $montant = $scenario === 'complet'
            ? $du
            : (int) round($du * $this->faker->numberBetween(30, 70) / 100);

        $this->repartirEnLignes($paiement, $montant, $donnees);
    }

    private function repartirEnLignes(Paiement $paiement, int $montant, array $donnees): void
    {
        $mode = $this->faker->randomElement(['cheques', 'cheques', 'carte', 'espece']);

        if ($mode !== 'cheques') {
            LignePaiement::create([
                'paiement_id' => $paiement->id,
                'type_paiement' => $mode,
                'montant' => $montant,
            ]);

            return;
        }

        $nb = $this->faker->numberBetween(2, 3);
        $part = intdiv($montant, $nb);
        $banque = $this->faker->randomElement(self::BANQUES);
        $emetteur = $donnees['responsables'][0];

        foreach (range(1, $nb) as $i) {
            LignePaiement::create([
                'paiement_id' => $paiement->id,
                'type_paiement' => 'cheque',
                // Le dernier chèque absorbe l'arrondi : le total tombe juste et
                // le garde-fou anti-dépassement de l'API reste satisfait.
                'montant' => $i === $nb ? $montant - $part * ($nb - 1) : $part,
                'details' => [
                    'banque' => $banque,
                    'numero' => (string) $this->faker->numberBetween(1000000, 9999999),
                    'nom_emetteur' => $emetteur->first_name.' '.$emetteur->last_name,
                ],
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     | Commentaires et familles archivées
     |--------------------------------------------------------------------- */

    /** Une famille sur trois porte une note au dossier. */
    private function creerCommentaires(array $familles, array $staff): void
    {
        $auteurs = [$staff['director'], $staff['admin'], $staff['registar']];

        foreach ($familles as $i => $donnees) {
            if ($i % 3 !== 0) {
                continue;
            }

            Comment::create([
                'family_id' => $donnees['family']->id,
                'user_id' => $this->faker->randomElement($auteurs)->id,
                'content' => $this->faker->randomElement([
                    'Règlement échelonné accepté par la direction.',
                    'Souhaite être contactée par SMS uniquement.',
                    'Fratrie inscrite depuis trois ans.',
                    'Dossier incomplet : attestation de domicile manquante.',
                    'Demande de changement de créneau pour l\'aîné.',
                ]),
            ]);
        }
    }

    /**
     * Trois archivages par école, placés exprès de part et d'autre de la
     * clôture de N-1, pour rendre observables les deux règles subtiles :
     *
     *   - l'archive ne montre que les suppressions de l'année consultée ;
     *   - une famille archivée APRÈS la clôture d'une année reste visible
     *     dans cette année-là (VisibleUntilYearClosedScope), mais n'apparaît
     *     pas dans son archive.
     */
    private function supprimerQuelquesFamilles(array $annees, array $familles): void
    {
        [$anneeN2, $anneeN1, $anneeN] = $annees;

        // Archivée pendant N-1, alors que l'année était encore ouverte.
        $this->supprimer($familles[1], $anneeN1->opened_at->copy()->addMonths(4), $anneeN1);

        // Archivées pendant l'année en cours. La première avait des
        // inscriptions en N-1 : elle doit rester visible dans cette archive.
        $this->supprimer($familles[2], $anneeN->opened_at->copy()->addDays(20), $anneeN);
        $this->supprimer($familles[3], Carbon::now()->subDays(3), $anneeN);
    }

    /**
     * Reproduit exactement ce que fait FamilyDeletionController::destroy() :
     * quatre ensembles de lignes marqués du MÊME deleted_at, sans quoi
     * restore() ne saurait pas quoi ressusciter.
     */
    private function supprimer(array $donnees, Carbon $date, SchoolYear $annee): void
    {
        $familyId = $donnees['family']->id;

        $inscriptions = StudentClassroom::query()
            ->withoutGlobalScopes()
            ->where('family_id', $familyId)
            ->where('school_year_id', $annee->id)
            ->get(['id', 'classroom_id', 'student_id']);

        // On désactive avant de couper : l'application refuse d'archiver une
        // famille qui a encore une inscription active. Le jeu de données reste
        // donc atteignable par le parcours réel.
        StudentClassroom::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $inscriptions->pluck('id'))
            ->update(['status' => 'inactive', 'deleted_at' => $date]);

        if ($inscriptions->isNotEmpty()) {
            UserRole::query()
                ->withoutGlobalScopes()
                ->where('roleable_type', 'classroom')
                ->whereIn('roleable_id', $inscriptions->pluck('classroom_id')->unique())
                ->whereIn('user_id', $inscriptions->pluck('student_id')->unique())
                ->update(['deleted_at' => $date]);
        }

        UserRole::query()
            ->withoutGlobalScopes()
            ->where('roleable_type', 'family')
            ->where('roleable_id', $familyId)
            ->update(['deleted_at' => $date]);

        Family::query()
            ->withoutGlobalScopes()
            ->whereKey($familyId)
            ->update(['deleted_at' => $date]);
    }

    /* ---------------------------------------------------------------------
     | Utilitaires
     |--------------------------------------------------------------------- */

    private function creerCompte(string $prenom, string $nom): User
    {
        return User::create([
            'first_name' => $prenom,
            'last_name' => $nom,
            'email' => strtolower($this->slug($prenom).'.'.$this->slug($nom).'@'.$this->domaine),
            'password' => Hash::make('password'),
            'access' => true,
        ]);
    }

    private function creerMembre(Family $famille, string $slug, string $nomFamille, string $age): User
    {
        $prenom = $this->faker->firstName();
        $this->compteur++;

        $user = User::create([
            'first_name' => $prenom,
            'last_name' => $nomFamille,
            'email' => $this->slug($prenom).'.'.$this->slug($nomFamille)
                .'.'.$this->compteur.'@'.$this->domaine,
            'password' => Hash::make('password'),
            'access' => true,
        ]);

        $this->rattacherRole($user, $slug, 'family', $famille->id);

        // Un « enfant » a entre 6 et 15 ans : c'est ce qui le rend éligible aux
        // classes Enfants. Au-delà, il relève des classes Hommes / Femmes.
        $naissance = $age === 'enfant'
            ? $this->faker->dateTimeBetween('-15 years', '-6 years')
            : $this->faker->dateTimeBetween('-55 years', '-19 years');

        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'birthdate',
            'value' => $naissance->format('Y-m-d'),
        ]);
        UserInfo::create([
            'user_id' => $user->id,
            'key' => 'gender',
            'value' => $this->faker->randomElement(['M', 'F']),
        ]);

        if ($slug === 'responsible') {
            UserInfo::create(['user_id' => $user->id, 'key' => 'phone', 'value' => $this->faker->mobileNumber()]);
            UserInfo::create(['user_id' => $user->id, 'key' => 'address', 'value' => $this->faker->streetAddress()]);
            UserInfo::create(['user_id' => $user->id, 'key' => 'city', 'value' => $this->faker->city()]);
            UserInfo::create(['user_id' => $user->id, 'key' => 'zipcode', 'value' => $this->faker->postcode()]);
        }

        return $user;
    }

    private function genreDe(User $user): string
    {
        return UserInfo::where('user_id', $user->id)->where('key', 'gender')->value('value') ?? 'M';
    }

    /**
     * accepted_at posé d'office : sans lui, SchoolContext refuse l'accès et
     * CheckRole rejette tous les rôles. Un compte seedé doit être utilisable
     * immédiatement, sans passer par le mail d'invitation.
     */
    private function rattacherRole(User $user, string $slug, string $type, int $id): void
    {
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $this->roleId($slug),
            'roleable_type' => $type,
            'roleable_id' => $id,
            'accepted_at' => $type === 'school' ? Carbon::now() : null,
        ]);
    }

    private function roleId(string $slug): int
    {
        static $cache = [];

        return $cache[$slug] ??= Role::where('slug', $slug)->value('id');
    }

    private function slug(string $valeur): string
    {
        $sansAccent = iconv('UTF-8', 'ASCII//TRANSLIT', $valeur);

        return strtolower(preg_replace('/[^a-zA-Z]/', '', $sansAccent));
    }

    private function restaurerContexte(?int $schoolId, ?int $yearId): void
    {
        request()->attributes->set('current_school_id', $schoolId);
        request()->attributes->set('current_school_year_id', $yearId);
    }

    private function recapitulatif(): void
    {
        $this->command?->info('DemoSeeder terminé.');

        foreach (self::ECOLES as $config) {
            $school = School::where('email', $config['email'])->first();
            $annees = SchoolYear::withoutGlobalScopes()->where('school_id', $school->id)->orderBy('id')->get();
            $familles = Family::withoutGlobalScopes()->where('school_id', $school->id);

            $this->command?->line('');
            $this->command?->line($school->name.' — '.$school->city);
            $this->command?->line('  directeur : '
                .strtolower($this->slug($config['staff']['director'][0]).'.'
                .$this->slug($config['staff']['director'][1]).'@'.$config['domain']).' / password');
            $this->command?->line('  familles  : '.$familles->clone()->whereNull('deleted_at')->count()
                .' actives, '.$familles->clone()->whereNotNull('deleted_at')->count().' archivées');

            foreach ($annees as $annee) {
                $classes = Classroom::withoutGlobalScopes()->where('school_year_id', $annee->id)->pluck('id');
                $inscrits = StudentClassroom::withoutGlobalScopes()
                    ->whereIn('classroom_id', $classes)->where('status', 'active')
                    ->whereNull('deleted_at')->count();
                $decisions = StudentYearOutcome::whereIn('classroom_id', $classes)->count();
                $seances = Attendance::withoutGlobalScopes()
                    ->where('school_year_id', $annee->id)->distinct()->count('date');

                $this->command?->line('  '.$annee->label.($annee->closed_at ? ' (clôturée)' : ' (active)  ')
                    .' : '.str_pad($inscrits, 3, ' ', STR_PAD_LEFT).' inscriptions · '
                    .str_pad($decisions, 3, ' ', STR_PAD_LEFT).' décisions · '
                    .$seances.' séances d\'émargement');
            }
        }

        $this->command?->line('');
        $this->command?->warn('Ces comptes ne sont pas super-admin : ajoute leur email à SUPER_ADMIN_EMAILS pour l\'espace /admin.');
    }
}
