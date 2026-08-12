---
name: laravel-model-migration
description: Ajouter ou modifier un modèle Eloquent, une migration, une colonne ou une relation dans l'API Toollab — choix des traits (BelongsToSchool, BelongsToSchoolYear, TrackChangesTrait), règles de $fillable, casts, conventions de migration, backfill, et vérification d'impact. À invoquer avant toute évolution de schéma ou de modèle.
---

# Modèles & migrations

## 1. Décider des traits — l'arbre de décision

```
La donnée appartient-elle à une école ?
├── oui → use BelongsToSchool;      (+ colonne school_id FK NOT NULL)
└── non → School, User, Role, UserInfo, InvitationToken…

La donnée est-elle remise à zéro / re-saisie chaque année scolaire ?
├── oui → use BelongsToSchoolYear;  (+ colonne school_year_id FK RESTRICT NOT NULL)
│         (Classroom, Tarif, Reduction*, Paiement, StudentClassroom, Attendance)
└── non → référentiel permanent (Cursus, CursusLevel)

La table est-elle modifiable par un utilisateur ?
└── oui → use TrackChangesTrait;    (+ colonnes created_by ET updated_by nullables)
```

⚠ `TrackChangesTrait` écrit `updated_by` sur l'événement `updating` : **la colonne doit exister**, sinon l'UPDATE part avec une colonne fantôme. (`StudentClassroom` est dans ce cas — il n'est jamais `update()` en pratique, mais ne reproduis pas l'oubli.)

## 2. Squelette d'un modèle

```php
namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\BelongsToSchoolYear;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Model;

class Machin extends Model
{
    use BelongsToSchool, BelongsToSchoolYear, TrackChangesTrait;

    protected $fillable = [
        // UNIQUEMENT les champs saisis par l'utilisateur.
        // JAMAIS school_id, school_year_id, created_by, updated_by.
        'name',
        'classroom_id',
    ];

    protected $casts = [
        'date' => 'date',
        'payload' => 'array',
        'actif' => 'boolean',
        'montant' => 'integer',
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
```

Conventions locales observées :
- `$casts` en **propriété** partout sauf `User` et `FamilyImport` qui utilisent la **méthode** `casts(): array` (style Laravel 11). Les deux sont acceptés — suis le style du fichier voisin.
- Nommage mixte assumé : `Paiement`, `LignePaiement`, `ReductionFamiliale` en FR ; `Classroom`, `Family`, `User` en EN. **Ne renomme rien** pour « harmoniser ».
- Table explicite quand le pluriel Laravel ne colle pas : `protected $table = 'cursus';`, `protected $table = 'lignes_paiement';`.

## 3. Attributs calculés (`$appends`) — attention au N+1

`Classroom` porte `$appends = ['student_count', 'available_spots']`, chaque accesseur déclenchant un `COUNT`. Sur une liste de 50 classes → 50 requêtes.

```php
// ✗ n+1
$classrooms = Classroom::all();

// ✓
$classrooms = Classroom::withCount('activeStudents')->get();  // → active_students_count
```

**N'ajoute pas de nouvel `$appends` coûteux.** Si tu as besoin d'un agrégat, expose-le via `withCount` / une sous-requête dans le contrôleur.

## 4. Relations — les pièges Toollab

```php
// ✓ relation classique
public function classroom() { return $this->belongsTo(Classroom::class); }

// ⚠ PIÈGE : Family::responsibles() / students()
// Leur closure whereHas référence $this->id → null pendant l'eager loading
// → with('responsibles') renvoie TOUJOURS vide.
```
Pour charger en masse les membres de N familles, **batcher via `UserRole`** :
```php
$rolesByFamily = UserRole::where('roleable_type', 'family')
    ->whereIn('roleable_id', $familyIds)
    ->whereHas('role', fn($q) => $q->whereIn('slug', ['responsible', 'student']))
    ->with(['role:id,slug', 'user.infos'])
    ->get()
    ->groupBy('roleable_id');
```
C'est le pattern utilisé par `computeFamilyFinancials`, `formatPaymentLignes`, `collectUnpaidFamilies`, `searchPayments`, `exportStudents`.

Autre piège : une personne responsable **et** élève apparaît deux fois → `->unique('id')` (cf. `PaiementController::facture`).

## 4 bis. Observers — il en existe deux, et ils sont piégeux

Enregistrés à la main dans `AppServiceProvider::boot()` (pas d'attribut `#[ObservedBy]`) :

| Observer | Modèle | Ce qu'il fait réellement |
|---|---|---|
| `UserObserver` | `User` | `updating()` seulement |
| `UserInfoObserver` | `UserInfo` | `updating()` seulement |

Tous leurs autres hooks (`created`, `updated`, `deleted`, `restored`, `forceDeleted`) ont un **corps vide** — squelettes générés par `artisan`. Ne pas en déduire qu'il s'y passe quelque chose.

Les deux `updating()` exécutent le même mass update :
```php
UserRole::where('user_id', $user->id)->update(['updated_by' => auth()->id(), 'updated_at' => now()]);
```
Trois conséquences à garder en tête avant de modifier un `User`/`UserInfo` :
1. **`UserRole` n'a pas `BelongsToSchool`** → l'update touche les rôles de la personne dans **toutes les écoles** (cf. `bugs-connus` C4 quinquies) ;
2. c'est un **mass update** : il court-circuite `TrackChangesTrait` et n'émet aucun événement modèle ;
3. il s'appuie sur `auth()->id()` → **inopérant hors HTTP** (job, commande, seeder), où il ne fait simplement rien.

Si tu ajoutes un observer, préfère le `booted()` du modèle ou un trait — et **scope explicitement à l'école courante**, l'observer ne le fera pas pour toi.

## 5. Écrire une migration

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('main_teacher_id')->nullable()->after('level_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_teacher_id');
        });
    }
};
```

Conventions du projet :
- Nom de fichier daté `AAAA_MM_JJ_HHMMSS_verbe_objet.php` (les migrations récentes utilisent des heures rondes `100000`, `120000` pour contrôler l'ordre).
- Classe **anonyme** (`return new class extends Migration`).
- Politique de FK : `cascadeOnDelete()` pour une dépendance forte (enfant sans parent = non-sens), `restrictOnDelete()` pour protéger une référence structurante (`school_year_id`, `class_schedules.teacher_id`), `nullOnDelete()` pour un lien optionnel (`main_teacher_id`, `decided_by`, `user_id` d'un commentaire).
- ⚠ **Toujours écrire le `onDelete` explicitement.** Un `->constrained()` nu vaut **RESTRICT** en MariaDB, ce qui bloque silencieusement des suppressions légitimes bien plus tard. Deux FK du schéma sont dans ce cas par omission — `user_roles.user_id` et `paiements.created_by` — et rendent `UserController::destroy` inopérant (`bugs-connus` A17). Vérifier l'impact d'un nouveau RESTRICT avec le tableau de `db-schema` § « Supprimer un User ».
- ⚠ Vérifier que le `down()` cible la **bonne table** : celui de `create_user_roles_table` fait `dropIfExists('role_user')` et ne rollback donc rien.

- `down()` **doit** être écrit, et défensif : `if (Schema::hasColumn(...))` avant de dropper.

⚠ **`user_roles` est POLYMORPHE, donc sans FK sur `roleable_id`.** Aucune cascade ne le nettoie. Toute suppression d'une entité pouvant être un `roleable` (école, famille, classe) **doit** supprimer explicitement ses `user_roles` :
```php
UserRole::where('roleable_type', 'classroom')->where('roleable_id', $id)->delete();
```
Deux chemins l'oublient déjà aujourd'hui (`bugs-connus` A11) — ne pas en ajouter un troisième.

## 6. Rendre une colonne NOT NULL : le pattern en 3 temps

Copié de `2026_06_01_100000_add_school_year_id_to_student_classrooms` :

```php
// 1. ajouter nullable
$table->foreignId('school_year_id')->nullable()->constrained('school_years')->restrictOnDelete();

// 2. backfill en SQL brut (rapide, pas d'événements Eloquent)
DB::statement('UPDATE student_classrooms sc
    INNER JOIN classrooms c ON c.id = sc.classroom_id
    SET sc.school_year_id = c.school_year_id WHERE sc.school_year_id IS NULL');

// 3. garde-fou AVANT de verrouiller
$orphans = DB::table('student_classrooms')->whereNull('school_year_id')->count();
if ($orphans > 0) {
    throw new \RuntimeException("Backfill incomplet : {$orphans} ligne(s) sans annee.");
}
$table->unsignedBigInteger('school_year_id')->nullable(false)->change();
```

Le `throw` est essentiel : mieux vaut une migration qui échoue qu'un `NOT NULL` posé sur des données incohérentes.

⚠ Les migrations de backfill n'ont **pas** de contexte HTTP : `currentSchoolId()` est null, les global scopes fail-closed. **Utilise `DB::table(...)` (query builder) et non Eloquent** dans une migration.

## 7. Changer un index / une contrainte unique

MariaDB via Laravel ne sait pas toujours dropper proprement un index créé par une autre migration :
```php
$table->unique(['family_id', 'school_year_id'], 'paiements_family_year_unique');
DB::statement('ALTER TABLE paiements DROP INDEX `paiements_family_id_unique`');
```
Nomme **toujours** tes contraintes composites (le nom auto dépasse vite la limite MySQL) : `syo_student_year_class_unique`, `att_student_class_date_unique`, `rmc_beneficiaire_requis_year_unique`.

## 8. Appliquer et vérifier

```bash
docker exec api_dev_toollab php artisan migrate --force
docker exec api_dev_toollab php artisan migrate:status
docker exec api_dev_toollab php artisan migrate:rollback --step=1   # tester le down()
docker exec api_dev_toollab php artisan migrate:fresh --seed        # reset complet dev
```
En prod, `production-entrypoint.sh` lance `migrate --force` automatiquement au démarrage, avec 40 tentatives de retry. Une migration qui échoue **bloque le déploiement** — teste toujours le `up()` ET le `down()` en local avant de tagger.

## 9. Checklist

- [ ] Traits corrects (école ? année ? audit ?) et colonnes correspondantes créées.
- [ ] `$fillable` = champs utilisateur seulement.
- [ ] `$casts` posés (`date`, `array`, `boolean`, `integer`).
- [ ] Pas de nouvel `$appends` coûteux.
- [ ] FK avec la bonne politique de suppression + rappel : **pas de soft delete**, donc cascade = perte définitive.
- [ ] `down()` écrit et défensif.
- [ ] Backfill en query builder + garde-fou avant de verrouiller.
- [ ] Skill `db-schema` mise à jour avec la nouvelle table/colonne.
- [ ] Modèle ajouté à la liste des traits dans la skill `multi-tenant-scoping`.

---

**Voir aussi** : `db-schema` (schéma exact) · `multi-tenant-scoping` (traits et scopes) · `securite-api` (mass assignment) · `tests` (pièges des factories)
