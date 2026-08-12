---
name: seeders-donnees-test
description: Seeders Toollab (RoleSeeder essentiel, ToollabSeeder de démo, AlQalamSeeder client réel, ProductionSeeder), bootstrap d'un super-admin sur base vierge via toollab:create-super-admin, et séquence d'initialisation d'une base de production. À invoquer pour réinitialiser un environnement, créer des données de test, ou initialiser une nouvelle instance.
---

# Seeders & données de test

## 1. Les 6 seeders et leur rôle

| Seeder | Appelé par | Contenu |
|---|---|---|
| **`RoleSeeder`** | `DatabaseSeeder`, `ProductionSeeder`, `AlQalamSeeder` | les 6 rôles. **LE SEUL INDISPENSABLE EN PROD** |
| `ToollabSeeder` | `DatabaseSeeder` | jeu de démo complet (École Arabe Al-Hikma) — **dev uniquement** |
| `AlQalamSeeder` | manuel | école cliente réelle (Al Qalam de Vitrolles) + ses membres, sans envoi de mail |
| `ProductionSeeder` | manuel | n'appelle que `RoleSeeder` — **point d'entrée prod** |
| `DatabaseSeeder` | `migrate --seed` | `RoleSeeder` + `ToollabSeeder` → **dev uniquement** |
| `UserSeeder`, `SchoolSeeder` | ❌ personne | **code mort** |

⚠ **Sans les rôles, `POST /api/schools` plante en 500** (`Role::where('slug','director')->first()` → null). C'est l'erreur n°1 sur une base non seedée.
`RoleSeeder` est **idempotent** (`firstOrCreate` par slug) — `roles.slug` n'ayant pas de contrainte unique, un ancien `Role::insert` créait des doublons.

## 2. Jeu de démo — `ToollabSeeder` (~50 s)

École **Arabe Al-Hikma**, directeur **`relhanti@gmail.com` / `password`**.

- **2 cursus** : **Arabe** (270 €, niveaux « 1ère année »…« 5ème année », réduction fratrie 11,11 % dès 3 élèves et 22,22 % dès 5 → 240/210 €) et **Coran** (150 €, progression `continu`, réduction multi-cursus −50 % si Arabe aussi → 75 €).
- **21 classes** aux noms courts : « 1ère A »…« 5ème C » (3 lettres × 5 niveaux, genres Enfants/Femmes/Hommes) + « Coran A »…« Coran F ».
- **9 professeurs** avec créneaux ; `main_teacher_id` positionné (premier prof des créneaux).
- **60 familles**, ~190 élèves.
- **Cohérence par construction** : ~95 % des élèves sur 1 cursus, ~5 % sur les deux (jamais plus), ~1/3 non inscrits ; familles 15 % sans inscrit / 25 % partiellement / 60 % toutes inscrites ; paiements 25 % rien / 35 % complet / 30 % partiel / 10 % exonération — **jamais de dépassement** (vérifié contre `TarifCalculatorService`) ; modes mixtes (1-3 chèques avec infos complètes, CB, espèces) ; **capacité des classes respectée**.
- Les détails de chèque utilisent les clés **API** (`banque`, `numero`, `nom_emetteur`), plus les clés legacy `emetteur`/`motif`.

### Ce que le seed fabrique exactement

**Classes** (`createClasses`) :
- Arabe : 5 niveaux × 3 lettres `A/B/C` → « 1ère A »…« 5ème C », `type = 'Arabe'`, genre **par index de lettre** (A→Enfants, B→Femmes, C→Hommes) ;
- Coran : 6 lettres `A`…`F` → « Coran A »…« Coran F », `type = 'Coran'`, `level_id = null`, genre cyclique (`$j % 3`) ;
- `size` tiré dans `{15, 20, 25}`.

**Professeurs** (`createTeachersAndSchedules`) : 9 comptes `prenom.nom@alhikma.fr`, mot de passe **`password`**, rôle `teacher` sur l'école.
Créneaux : **1 à 2 par classe**, jour aléatoire × 6 créneaux possibles (`09:00-11:00`, `10:00-12:00`, `11:00-13:00`, `14:00-16:00`, `15:30-17:30`, `17:00-19:00`), anti-doublon `jour|heure` avec 10 tentatives. `main_teacher_id` = **premier prof tiré**.

⚠ **`classrooms.type` vaut `'Arabe'`/`'Coran'` dans le seed**, alors que l'UI pose toujours `'Standard'`. Ne pas généraliser depuis l'un ou l'autre.

### Architecture interne

`run()` orchestre 8 étapes, chacune alimentant des propriétés partagées (`$this->classrooms`, `$this->families`, `$this->enrollmentPlan`…) :
```
createSchoolAndDirector → createCursusEtTarification → createClasses
→ createTeachersAndSchedules → createFamilies → createStudents
→ enrollStudentsInClasses → createPayments
```
La cohérence vient de `$this->enrollmentPlan[$familyId] = ['arabe' => n, 'coran_only' => n, 'both' => n]`, construit à l'inscription et **relu** par `createPayments()`.

Distribution des tailles de familles (`generateStudentDistribution`) : 8×1, 14×2, 14×3, 12×4, 8×5, 4×6 élèves, mélangée.

**Élèves** (`createStudents`) :
- **70 % sont des « enfants »** → naissance entre −15 et −6 ans ; les 30 % restants entre −30 et −16 ans (adultes, pour peupler les classes Hommes/Femmes) ;
- le **nom de famille est repris du responsable**, le prénom est tiré au sort ;
- ⚠ l'email est un `faker->unique()->safeEmail` — **pas** le format `prenom.nom.student.<uniqid>@school.com` que produit l'application. Ne pas se fier au seed pour reconnaître un élève par son email.

**Inscriptions** (`enrollStudentsInClasses`) :
```
profil famille  : 15 % aucun inscrit · 25 % partiel (55 % de chances par élève) · 60 % tous
par élève       : roll 1-100 → wantsArabe = roll ≤ 80 ; wantsCoran = roll > 75
                  ⇒ 75 % Arabe seul · 20 % Coran seul · 5 % LES DEUX (rolls 76-80)
```
`enrollInCursus()` choisit la classe **selon le genre de l'élève** : `Enfants` si enfant, sinon `Femmes`/`Hommes` — le seed respecte donc la même règle de compatibilité que le filtrage front (`shouldShowClass`), et la capacité des classes est suivie via `$this->classroomCounts`.
Scénarios de paiement (`createPayments`) : 25 % `unpaid` · 35 % `full` · 30 % `partial` (30-75 %) · 10 % `exoneration` (moitié totale, moitié partielle — et dans 70 % des cas partiels, le reste est réglé).

Répartition en lignes (`createPaymentLines`), pondérée sur 11 tirages :
```
cheques3 ×2   2 à 3 chèques (dernier ajusté pour tomber juste)
cheque1  ×3   un seul chèque
carte    ×2 · espece ×2
mix_espece_cheque (40/60) · mix_carte_espece (60/40)
```
- **Une seule banque par famille** (tirée dans les 8 banques de `self::BANQUES`), numéro à 7 chiffres, `nom_emetteur` = nom du responsable.
- Les détails de chèque utilisent les **clés API** (`banque`, `numero`, `nom_emetteur`) : le seed ne produit **jamais** les clés legacy `emetteur`/`motif` — celles-ci ne peuvent venir que de données de production historiques.
- Les exonérations ont un `justification` tiré parmi 3 motifs réalistes.

### ⚠ PIÈGE : `computeFamilyTotal()` réimplémente la tarification EN DUR

```php
private function computeFamilyTotal(array $plan): int
{
    $prixArabe = $plan['arabe'] >= 5 ? 210 : ($plan['arabe'] >= 3 ? 240 : 270);
    return $prixArabe * $plan['arabe'] + 150 * $plan['coran_only'] + 75 * $plan['both'];
}
```
Ces montants **dupliquent** ceux posés par `createCursusEtTarification()` (Arabe 270 € avec −11,11 % dès 3 et −22,22 % dès 5 ; Coran 150 € avec −50 % en multi-cursus). Le seeder **n'appelle pas** `TarifCalculatorService`.

**Conséquence** : modifier un tarif ou un palier dans `createCursusEtTarification` **sans** mettre à jour `computeFamilyTotal` produit des paiements qui **dépassent le montant dû** → familles en trop-perçu, statuts incohérents, et un jeu de démo qui contredit le garde-fou 422 de l'application.
→ **Toujours modifier les deux méthodes ensemble**, ou refactorer `computeFamilyTotal` pour déléguer au service.

### Le point technique à connaître

Le seeder **mocke `request()->attributes`** après création de la `SchoolYear` :
```php
request()->attributes->set('current_school_id', $school->id);
request()->attributes->set('current_school_year_id', $year->id);
```
Sans cela, les traits `BelongsToSchool`/`BelongsToSchoolYear` ne posent rien et les global scopes fail-closed renvoient 0 ligne → le seeder échoue ou crée des données orphelines. **Tout nouveau seeder qui écrit des données scopées doit faire pareil.**

## 3. `AlQalamSeeder` — école cliente réelle (données de production)

Crée l'« Association Al Qalam de Vitrolles » — **`siret` comme clé d'idempotence**, `vat_mode = 'association'`, adresse réelle (1 Bd Paul Guigou, 13127 Vitrolles) — puis rattache ses membres :

| Rôle | Compte |
|---|---|
| `director` | `habibmal@hotmail.fr` |
| `admin` | `ajjaj.manelle@gmail.com` |
| `admin` | `imene.re13@gmail.com` |

Tous avec le mot de passe **`password`** et **`accepted_at = now()`** — donc **sans envoi d'e-mail d'invitation** et sans étape d'acceptation. La commande réaffiche les identifiants en fin d'exécution.

`ensureActiveSchoolYear()` crée l'année `AAAA-AAAA+1` (bascule au 1er septembre) **si elle n'existe pas**, puis **désactive toutes les autres** (`is_active = false`) — garantit l'invariant « une seule année active ». Tout passe par `withoutGlobalScopes()` (pas de contexte HTTP dans un seeder).

Trois helpers réutilisables pour créer un autre seeder d'école cliente : `firstOrCreateUser()`, `attachSchoolRole()`, `ensureActiveSchoolYear()`.

⚠ Bug historique corrigé (`19d0583`) : une variable `$admin` réassignée dans la boucle écrasait le membre précédent. Si tu ajoutes des membres, **utilise une variable de boucle distincte** (le code actuel utilise `$member`, c'est correct).

```bash
docker exec api_dev_toollab php artisan db:seed --class=AlQalamSeeder --force
```
⚠ Ce seeder contient de **vraies adresses e-mail de clients** avec un mot de passe connu. Ne pas l'exécuter sur une instance exposée sans changer les mots de passe ensuite.

## 4. Super-admin — bootstrap œuf/poule

Tout, y compris `POST /api/schools`, exige un super-admin **connecté**. Sur base vierge, **aucun moyen in-app** de créer le premier compte.

```bash
# tous les emails de SUPER_ADMIN_EMAILS, même mot de passe
docker exec -it api_dev_toollab php artisan toollab:create-super-admin --password=motdepasse

# un email précis
docker exec -it api_dev_toollab php artisan toollab:create-super-admin admin@ex.com --password=…
```

La commande (`app/Console/Commands/CreateSuperAdmin.php`) est **idempotente** : elle crée le user ou **réinitialise son mot de passe**. Sans argument, elle traite **tous** les emails de `SUPER_ADMIN_EMAILS` (CSV) ; en interactif elle demande le mot de passe un par un. Minimum 8 caractères. Elle avertit si l'email n'est pas dans l'env (le compte sera créé mais **pas** super-admin).

Rappels :
- `is_super_admin` est **dérivé de l'email**, jamais stocké.
- **En prod la config est cachée** : après modification de `SUPER_ADMIN_EMAILS`, relancer `php artisan config:cache` ou redémarrer le conteneur.
- Un super-admin **sans école** atterrit directement sur `/admin` après login. Le compte seedé `relhanti@gmail.com` a une école (directeur) → il atterrit sur `/` et bascule vers `/admin` via le menu compte.

## 5. Séquences

### Dev — repartir de zéro avec des données
```bash
docker exec api_dev_toollab php artisan migrate:fresh --seed --force
```

### Dev — base propre, sans fausse donnée
```bash
docker exec api_dev_toollab php artisan migrate:fresh --force
docker exec api_dev_toollab php artisan db:seed --class=ProductionSeeder --force
docker exec -it api_dev_toollab php artisan toollab:create-super-admin --password=password
```

### Production — initialisation d'une nouvelle instance
```bash
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan toollab:create-super-admin --password=…
```
**Ne jamais lancer `db:seed` sans `--class` en prod** : `DatabaseSeeder` injecterait le jeu de démo.
`production-entrypoint.sh` lance déjà `migrate --force` au démarrage ; les deux autres étapes sont manuelles et ponctuelles.

## 6. Écrire un nouveau seeder

```php
class MonSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(RoleSeeder::class);

            $school = School::firstOrCreate(['email' => '…'], [ … ]);
            $year = SchoolYear::firstOrCreate(
                ['school_id' => $school->id, 'label' => '2026-2027'],
                ['opened_at' => now(), 'is_active' => true]
            );

            // OBLIGATOIRE avant toute écriture de données scopées
            request()->attributes->set('current_school_id', $school->id);
            request()->attributes->set('current_school_year_id', $year->id);

            // … création des données
        });
    }
}
```
Conventions : **idempotent** (`firstOrCreate` sur une clé naturelle), transaction unique, `Faker::create('fr_FR')` pour les données réalistes, mots de passe via `Hash::make(...)` (un hash partagé suffit pour des comptes non connectables).

## 7. Vérifier un seed

```bash
docker exec api_dev_toollab php artisan tinker --execute="
  echo \App\Models\School::count().' écoles, '
     .\App\Models\Family::withoutGlobalScopes()->count().' familles, '
     .\App\Models\Classroom::withoutGlobalScopes()->count().' classes';
"
```
⚠ En tinker, **aucun contexte HTTP** : sans `withoutGlobalScopes()`, tous les comptages scopés renvoient **0**.

---

**Voir aussi** : `docker-dev` · `super-admin-ecoles` (bootstrap) · `tarification-paiements` (cohérence des montants) · `tests`
