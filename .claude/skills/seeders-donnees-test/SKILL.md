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
| **`DemoSeeder`** | manuel | **2 écoles × 3 années** (2 clôturées), familles supprimées incluses — **dev uniquement** |
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

## 2 bis. Multi-écoles & multi-années — `DemoSeeder` (~20 s)

**Le seul seeder qui produit des années CLÔTURÉES.** `ToollabSeeder` et `AlQalamSeeder` créent chacun une unique année active : sans `DemoSeeder`, le code d'archive, de lecture seule, de reconduction et de corbeille bornée à l'année n'a jamais de données pour tourner.

```bash
docker exec api_dev_toollab php artisan db:seed --class=DemoSeeder --force
```

Deux écoles, **Institut An-Nour** (Lyon) et **Centre Al-Fajr** (Marseille), chacune avec :

| | Contenu |
|---|---|
| Années | N-2 et N-1 **clôturées**, N active |
| Staff | director / admin / registar + 3 professeurs, tous `accepted_at` posé, mot de passe `password` |
| Cursus | Arabe (3 niveaux) et Coran (`continu`) — **permanents**, ils traversent les années |
| Tarifs | refaits **par année**, en hausse (240→255→270 et 130→140→150) pour rendre visible le rejeu annuel |
| Classes | 9 par année : Arabe 3 niveaux × 2 groupes (`Enfants`) + Coran `Enfants`/`Femmes`/`Hommes` |
| Familles | 20 permanentes, dont 10 qui **arrivent en cours de route** (recrutement de rentrée) |
| Supprimées | **3 par école** : 1 pendant N-1 (année alors ouverte), 2 pendant N |
| Paiements | sur les **trois** années ; une année close est toujours soldée (pas d'impayé traînant dans un exercice terminé) |
| Décisions | sur les années **closes** uniquement ; N-1 laissée à ~75 % pour que `/decisions` affiche un taux réaliste. Elles **pilotent l'année suivante** |
| Émargement | 8 séances hebdomadaires par classe sur N-1 et N (85 % présents, 8 % justifiés avec motif, 7 % non justifiés) |
| Divers | commentaires de dossier, **1 invitation non acceptée** par école, `outcomes_open` **activé chez An-Nour seulement** |

Directeurs : `yacine.belkacem@an-nour.fr` et `rachid.toumi@al-fajr.fr`, mot de passe `password`. **Aucun n'est super-admin** — ajouter l'email à `SUPER_ADMIN_EMAILS` pour l'espace `/admin`.

### Une SIMULATION, pas un tirage au sort

Les trois années ne sont pas peuplées indépendamment : `simulerScolarite()` rejoue la scolarité de chaque élève année après année, et **la décision de fin d'année pilote l'inscription de l'année suivante**.

| Décision en N-1 | Effet en N |
|---|---|
| `passage` | monte d'un niveau |
| `redoublement` | même niveau |
| `fin_cursus` | quitte ce cursus |
| `exclusion` | quitte l'école |

Mesuré sur le jeu produit : **20 décisions sur 21** se retrouvent appliquées à l'année suivante (la 21ᵉ appartient à une famille archichée entre-temps).

Les familles qui **arrivent en cours de route inscrivent en 1ère année** — c'est le recrutement de la rentrée, sans lui les petits niveaux se vident au fil des passages.

### Les invariants tenus, parce que l'application les impose

| Invariant | Pourquoi |
|---|---|
| Classe compatible avec le profil (enfant → `Enfants`, adulte → `Hommes`/`Femmes`) | une version antérieure mettait 44 élèves dans une classe du mauvais genre |
| Capacité jamais dépassée | `placesRestantes` suit chaque classe |
| Un cursus = une classe par élève et par année | c'est le pattern *replace* de `enroll` |
| **`tarif_snapshot` sur chaque inscription** | réplique de `buildTarifSnapshot()` ; sans lui les montants sont recalculés au tarif courant et ne sont plus ceux du dossier |
| `passage`/`redoublement` interdits en cursus continu | l'API les refuse en 422 |
| Émargement et décisions réservés aux inscrits | pas de ligne orpheline |
| Élèves répartis entre les classes d'un même niveau | remplir « 1ère A » à saturation laissait « 1ère B » vide |

Requête d'audit prête à l'emploi dans `debug-api` — les huit compteurs doivent tous valoir 0.

### Ce que ce jeu rend observable

Les suppressions sont placées **exprès de part et d'autre de la clôture de N-1**, ce qui matérialise les deux règles les plus subtiles de la suppression de famille (voir skill `familles-eleves`) :

```
année consultée      liste des familles      corbeille
N-2 (close)          14  (les 3 supprimées ressuscitent)      0
N-1 (close)          13  (1 reste masquée, 2 ressuscitent)    1
N   (active)         11                                        2
```

### La règle du cursus continu est respectée

`passage` et `redoublement` sont **refusés en 422** sur un cursus `continu`. Un élève de Coran qui poursuit n'a donc **aucune décision** — seuls ceux qui partent ou sont exclus en portent une (~25 %). Un seeder qui déciderait tous les élèves Coran produirait un jeu que l'application elle-même refuserait de saisir.

### Trois différences avec `ToollabSeeder`, volontaires

- **Le montant dû est demandé à `TarifCalculatorService`**, pas recalculé en dur. `ToollabSeeder::computeFamilyTotal()` duplique la grille tarifaire et se désynchronise dès qu'un prix bouge ; `DemoSeeder` ne peut pas produire de trop-perçu (vérifié : 0 dépassement sur les 6 années).
- **Les e-mails des membres sont déterministes** (`prenom.nom.<n>@domaine`) et non des `faker->safeEmail()`. `faker->unique()` ne connaît que sa propre session : sur une base contenant déjà Al-Hikma, les `safeEmail()` entrent en collision avec l'index unique de `users`.

- **Les rôles école portent `accepted_at`.** `ToollabSeeder` ne le pose jamais : ses 9 professeurs Al-Hikma **ne peuvent pas accéder à leur école** (`SchoolContext::userHasAccess()` exige une adhésion acceptée), et le bandeau bleu d'invitation s'affiche en permanence. `DemoSeeder` pose `accepted_at` sur tous ses comptes sauf **un par école**, laissé en attente exprès.

### Rejouable — et les garde-fous de la purge

Le seeder **efface d'abord ses deux écoles** (`purgerEcolesDemo()`) puis les recrée. Un simple « skip si elle existe » interdirait d'enrichir le jeu de données sans repartir d'une base neuve.

La purge passe par des requêtes **SQL brutes** : les modèles portent des global scopes (école, année, soft delete conscient de l'année) qui masqueraient une partie des lignes et laisseraient des orphelins. Elle ne supprime un compte que s'il ne lui reste **aucun rôle ailleurs**.

⚠ **C'est la seule opération destructrice de tout le projet.** Trois verrous, tous vérifiés par un test manuel :

| Verrou | Effet |
|---|---|
| `app()->environment(['local','testing'])` | hors dev, le seeder s'arrête immédiatement sans rien lire |
| `siret === null` | une école cliente réelle porte un SIRET (Al Qalam), une école de démo jamais — un SIRET ⇒ **refus** |
| `name === config['name']` | l'adresse seule ne suffit pas ; si elle a été réutilisée, **refus** |

Un refus lève une `RuntimeException` **avant le premier DELETE** : la liste des écoles à purger est intégralement validée avant qu'une seule ligne ne soit touchée. Piéger la seconde école n'endommage donc pas la première.

**Al-Hikma, Al Qalam et les données saisies à la main ne sont jamais concernées.**

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
