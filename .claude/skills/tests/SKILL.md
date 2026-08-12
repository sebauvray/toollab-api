---
name: tests
description: Tests automatisés de Toollab — Pest 3 côté API (2 suites existantes, RefreshDatabase, pièges des global scopes en test), tests node:test côté front (utils/schoolRoles), comment écrire un nouveau test et lancer les suites. À invoquer pour ajouter un test, comprendre pourquoi un test échoue, ou avant de tagger une release.
---

# Tests

## 1. État réel de la couverture — 24 tests

**Ce qui est solidement testé** (à connaître : ce sont les seules régressions que la suite attrapera).

### `tests/Feature/FamilyImportServiceTest.php` — **13 tests**, couverture sérieuse
```
✔ importe un fichier valide avec deux responsables et une fratrie
✔ gère une famille de cinq élèves
✔ gère un élève majeur qui est son propre responsable   (1 User, 2 rôles)
✔ rejette un genre invalide sans rien écrire
✔ rejette une date de naissance invalide
✔ rejette une ligne sans responsable 1
✔ réutilise un responsable existant identifié par email
✔ ne remplace pas les informations d'un responsable existant   (setInfoIfMissing)
✔ rejette un élève déjà présent dans l'école
✔ rejette un fichier trop volumineux au lieu de l'importer partiellement
✔ applique le tout-ou-rien : une ligne invalide annule tout le fichier
✔ traite un import valide via le job                    (ProcessFamilyImportJob)
✔ marque un import en erreur via le job sans importer de doublon
```
Helpers : `makeCsv(rows)` écrit un CSV `;` temporaire (padding à 20 colonnes), `resp1()`/`resp2()`/`respHaddad()` fournissent des blocs responsable. `beforeEach` : `seed(RoleSeeder)` + `School::factory()` + **`request()->attributes->set('current_school_id', …)`**.

### `tests/Unit/StaffRolePermissionsTest.php` — 3 tests
Matrice complète `canManage` : director → admin/registar/teacher ; admin → registar/teacher seulement ; registar et tableau vide → rien.

### `toollab-front/tests/schoolRoles.test.mjs` — **8 tests** (`node --test`)
```
✔ groups every role by school without depending on API order
✔ redirects only users whose sole school role is teacher
✔ writes, reads and clears the current school role list
✔ reads the legacy single-role cache during transition
✔ defaults the active role to the highest priority available role
✔ keeps a valid active role across role-list reconciliation
✔ resets the active role when it is no longer available
✔ clearing roles also clears the active role
```
C'est le **contrat complet du rôle actif** — toute modification de `utils/schoolRoles.js` doit relancer cette suite.

### Ce qui n'est PAS testé du tout
Aucun test d'**endpoint HTTP**, de **tarification** (`TarifCalculatorService`), de **scoping multi-tenant**, de **paiements**, d'**émargement/décisions**, d'**export xlsx** ni de **facture PDF**. `tests/{Feature,Unit}/ExampleTest.php` sont les squelettes Laravel, sans valeur.

⚠ **Aucun test n'est exécuté par la CI** : les workflows GitHub ne font que construire et pousser les images. **Lancer les suites manuellement avant de tagger.**

## 2. Lancer

```bash
# prérequis UNE FOIS : la base `testing` doit exister sur MariaDB (cf. §3)
docker exec db_dev_toollab mysql -uroot -ppasswordroot \
  -e "CREATE DATABASE IF NOT EXISTS testing; GRANT ALL ON testing.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

# API
docker exec api_dev_toollab ./vendor/bin/pest
docker exec api_dev_toollab ./vendor/bin/pest tests/Unit/StaffRolePermissionsTest.php
docker exec api_dev_toollab ./vendor/bin/pest --filter="director"

# Front
docker exec nuxt_toollab npm run test:roles        # node --test tests/schoolRoles.test.mjs
```

## 3. Configuration Pest & environnement de test

`tests/Pest.php` :
```php
pest()->extend(Tests\TestCase::class)->in('Feature');
```
⚠ `RefreshDatabase` est **commenté globalement** : chaque test Feature qui touche la base doit le déclarer lui-même :
```php
uses(RefreshDatabase::class);
```

`phpunit.xml` surcharge l'environnement :
```xml
APP_ENV=testing   BCRYPT_ROUNDS=4        DB_DATABASE=testing
CACHE_STORE=array SESSION_DRIVER=array   MAIL_MAILER=array
QUEUE_CONNECTION=sync
```

Trois conséquences pratiques :
1. **`DB_DATABASE=testing` mais `DB_CONNECTION` n'est PAS surchargé** → les tests tapent sur **MariaDB**, pas sur SQLite en mémoire. La base `testing` doit exister, sinon tout échoue avec « Unknown database » :
   ```bash
   docker exec db_dev_toollab mysql -uroot -ppasswordroot -e "CREATE DATABASE IF NOT EXISTS testing;
     GRANT ALL ON testing.* TO 'sail'@'%'; FLUSH PRIVILEGES;"
   ```
2. **`QUEUE_CONNECTION=sync`** → les jobs s'exécutent **immédiatement**, dans la requête. C'est ce qui permet à `FamilyImportServiceTest` de tester `ProcessFamilyImportJob` sans worker. Corollaire : un test ne peut pas vérifier qu'un job a été *mis en file* sans `Queue::fake()`.
3. **`MAIL_MAILER=array`** → les mails sont capturés en mémoire ; utiliser `Notification::fake()` / `Mail::fake()` + assertions.

`tests/TestCase.php` est vide (pas de `CreatesApplication` explicite, Laravel 11 s'en charge).

## 4. Écrire un test — les pièges Toollab

### (a) Les global scopes fail-closed
En test, `currentSchoolId()` est `null` → `BelongsToSchool` renvoie **0 ligne**. Tout test qui lit des données scopées doit d'abord poser le contexte :
```php
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $school = School::factory()->create();
    $year = SchoolYear::query()->create([...]);  // school_id explicite

    request()->attributes->set('current_school_id', $school->id);
    request()->attributes->set('current_school_year_id', $year->id);
});
```
Sinon : soit tu utilises `withoutGlobalScopes()` dans les assertions, soit tes créations n'ont pas de `school_id` (le trait ne pose rien sans contexte) et tout devient invisible.

### (b) Les rôles doivent exister
Presque tout dépend de la table `roles` : `$this->seed(RoleSeeder::class)` en préambule, sinon `Role::where('slug', …)->first()` renvoie `null` et le code plante en 500.

### (c) Factories disponibles — et leurs pièges
Seulement 3 : `UserFactory`, `SchoolFactory`, `ClassroomFactory`. Pour le reste, création explicite (voir `FamilyImportServiceTest` qui construit ses fixtures à la main et écrit un CSV temporaire via une fonction helper).

⚠ **`ClassroomFactory` ne renseigne pas `school_year_id`**, colonne pourtant **NOT NULL** depuis `2026_05_25_100200`. L'utiliser telle quelle échoue en SQL, sauf si le contexte année est posé (le trait `BelongsToSchoolYear` remplit alors le champ) :
```php
request()->attributes->set('current_school_year_id', $year->id);
Classroom::factory()->create();                 // OK
Classroom::factory()->create(['school_year_id' => $year->id]);   // ou explicitement
```
Elle ne pose pas non plus `cursus_id`, `level_id`, `gender` (défaut DB `Mixte`).

⚠ **`UserFactory` et `SchoolFactory` mettent `access` à `fake()->boolean()`** — donc aléatoirement `false`. Sans effet aujourd'hui (la colonne n'est vérifiée nulle part), mais trompeur en debug : forcer `['access' => true]` si le test porte sur l'accès.

### (d) Storage
```php
Storage::fake('local');
```
Indispensable pour tester l'import (le job supprime le fichier en `finally`).

## 5. Quoi tester en priorité (dette de test)

Par ordre de valeur, si on décide d'investir :
1. **`TarifCalculatorService`** — `max(familiale, multiCursus)`, paliers, snapshot vs live. Logique pure, facile à tester, coût d'une régression : des factures fausses.
2. **Isolation multi-tenant** — un directeur de l'école A ne doit rien voir de l'école B (test Feature HTTP avec les deux headers).
3. **Garde-fou anti-dépassement** des paiements (422).
4. **Sémantique « état complet »** de `saveAttendance` / `saveOutcomes` (un payload vide efface).
5. **Middleware `schoolyear`** — 409 sur écriture en année archivée, GET autorisé.

## 6. Test HTTP — squelette (aucun n'existe encore)

```php
uses(RefreshDatabase::class);

it('refuse une famille d\'une autre école', function () {
    $this->seed(RoleSeeder::class);
    [$schoolA, $schoolB] = School::factory()->count(2)->create();
    $director = User::factory()->create();
    UserRole::create([
        'user_id' => $director->id,
        'role_id' => Role::where('slug', 'director')->value('id'),
        'roleable_type' => 'school', 'roleable_id' => $schoolA->id,
        'accepted_at' => now(),
    ]);
    $familyB = Family::query()->create(['school_id' => $schoolB->id]);

    $this->actingAs($director)
        ->withHeaders(['X-School-Id' => $schoolA->id])
        ->getJson("/api/families/{$familyB->id}")
        ->assertStatus(403);   // ou 404 selon le global scope
});
```
⚠ Ne pas oublier **`accepted_at`** : une adhésion non acceptée n'ouvre aucun accès (`SchoolContext`, `CheckRole`).

## 7. Front — `tests/schoolRoles.test.mjs`

Utilise `node --test` (pas de Vitest). Le module `utils/schoolRoles.js` est conçu **testable** : ses fonctions de lecture/écriture acceptent un paramètre `storage` optionnel, ce qui permet d'injecter un faux localStorage.
**Conserver cette signature** si tu ajoutes une fonction au module.

## 8. Checklist avant de tagger une release

- [ ] `./vendor/bin/pest` passe.
- [ ] `npm run test:roles` passe.
- [ ] Les migrations s'appliquent **et** se rollbackent (`migrate:rollback --step=1`).
- [ ] Smoke test manuel : login → sélection d'école → une liste year-scopée → une écriture → un export.

---

**Voir aussi** : `debug-api` · `securite-api` (quoi tester en priorité) · `multi-tenant-scoping` (scopes en test) · `docker-dev`
