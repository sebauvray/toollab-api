---
name: multi-tenant-scoping
description: Isolation multi-tenant Toollab par école (school_id) et par année scolaire (school_year_id) — global scopes, middlewares SchoolContext/SchoolYearContext, helpers currentSchoolId()/currentSchoolYearId(), et la règle critique de réinjection du contexte hors HTTP (jobs, commandes, seeders). À invoquer dès qu'une requête Eloquent, un job, une commande artisan ou un endpoint touche une donnée rattachée à une école ou à une année.
---

# Isolation multi-tenant : école + année scolaire

## 1. Les deux dimensions

Toute donnée métier Toollab est isolée sur **deux axes** :

| Axe | Colonne | Trait | Scope | Comportement sans contexte |
|---|---|---|---|---|
| École | `school_id` | `App\Traits\BelongsToSchool` | `BelongsToSchoolScope` | **FAIL-CLOSED** : `whereRaw('0 = 1')` → 0 ligne |
| Année | `school_year_id` | `App\Traits\BelongsToSchoolYear` | `BelongsToSchoolYearScope` | **PERMISSIF** : aucun filtre (l'axe école protège déjà) |

Les deux traits font aussi l'**auto-set à la création** (`creating` event) si le champ est vide.

## 2. Qui porte quoi

```
BelongsToSchool seul            : Family, Cursus, SchoolYear
BelongsToSchoolYear seul        : Paiement, Tarif, ReductionFamiliale,
                                  ReductionMultiCursus, StudentClassroom
Les deux                        : Classroom, Attendance
AUCUN des deux (⚠)              : User, UserRole, UserInfo, Role, School,
                                  ClassSchedule, CursusLevel, LignePaiement,
                                  Comment, StudentYearOutcome, InvitationToken,
                                  FamilyImport
```

**Conséquence directe** : sur un modèle sans scope, **tu dois filtrer à la main**.

- `StudentYearOutcome` : **toujours** scoper explicitement `school_year_id` ET `classroom_id`.
- `LignePaiement` : passe par `whereHas('paiement', …)` — le `whereHas` applique les global scopes de la relation, donc l'année est appliquée. **Ne crois pas à un « bug de scope année » sur les montants payés : il n'existe pas.**
- `ClassSchedule` : filtrer via `whereHas('classroom', fn($q) => $q->where('school_id', $schoolId))`.
- `UserRole` : filtrer par `roleable_type` + `roleable_id` (l'école, ou la liste des familles/classes de l'école).

## 3. Comment le contexte arrive

```
Requête HTTP
  → middleware `school`      (SchoolContext)      lit header X-School-Id
      valide : entier > 0, école existe, user y a accès (adhésion acceptée,
      ou rôle sur une famille/classe de cette école)
      → request()->attributes->set('current_school_id', $id)
      ⚠ coût : pour un NON-staff, ce test charge tous les ids de familles
        et de classes de l'école à chaque requête (cf. bugs-connus C2 ter)
  → middleware `schoolyear`  (SchoolYearContext)  lit header X-School-Year-Id
      défaut = année active de l'école ; sinon la plus récente
      → request()->attributes->set('current_school_year_id', $id)
      → si année archivée ET méthode ∈ {POST,PUT,PATCH,DELETE} : 409 + read_only
```

Helpers globaux (`app/Support/helpers.php`, autoloadés via composer `files`) :

```php
currentSchoolId(): ?int        // null si pas de contexte
currentSchoolYearId(): ?int
```

**Ordre des middlewares** (`bootstrap/app.php`, bloc `priority`) : `SuperAdmin` → `SchoolContext` → `SchoolYearContext` → `CheckRole` → **puis** `SubstituteBindings`. C'est volontaire : le Route Model Binding doit s'exécuter **après** que le contexte est posé, sinon le global scope fail-closed renvoie 0 ligne et tout part en 404.
→ **Ne jamais réordonner ce bloc sans comprendre cette contrainte.**

## 4. RÈGLE CRITIQUE — hors HTTP, le contexte n'existe pas

Dans un **job**, une **commande artisan**, un **seeder** ou un **test**, `request()->attributes` est vide → `currentSchoolId()` renvoie `null` → **le scope école fail-closed renvoie 0 ligne partout**. Symptôme typique : un job qui « ne trouve rien » alors que les données existent.

**Pattern obligatoire** (implémenté dans `CheckPaymentCompletionJob` et `ProcessFamilyImportJob`) :

```php
$previous = [
    request()->attributes->get('current_school_id'),
    request()->attributes->get('current_school_year_id'),
];
request()->attributes->set('current_school_id', $schoolId);
request()->attributes->set('current_school_year_id', $yearId);

try {
    // … travail ici : les global scopes fonctionnent normalement
} finally {
    request()->attributes->set('current_school_id', $previous[0]);
    request()->attributes->set('current_school_year_id', $previous[1]);
}
```

Le `finally` n'est pas cosmétique : un worker `queue:listen` traite plusieurs jobs dans le même process, une fuite de contexte contaminerait le job suivant (fuite cross-tenant).

⚠ **Une notification `ShouldQueue` doit refaire ce travail elle-même.** Elle est rendue par le worker, dans un process qui n'a plus le contexte du job appelant : `PaymentCompletedNotification` capture `school_id`/`school_year_id` **dans son constructeur**, puis les réinjecte dans `toMail()` (avec `finally`), et double la protection par des `withoutGlobalScopes()` explicites.

Les trois endroits du code qui appliquent ce pattern — à consulter comme référence : `CheckPaymentCompletionJob::handle()`, `ProcessFamilyImportJob::handle()`, `PaymentCompletedNotification::toMail()`.

Pour retrouver l'année d'un job qui n'a qu'une famille :
```php
$activeYear = SchoolYear::withoutGlobalScopes()
    ->where('school_id', $family->school_id)->where('is_active', true)->first();
```

## 5. Bypass volontaire du scope

Quand tu dois lire hors du contexte courant (résolution d'accès, listing multi-écoles, backfill) :

```php
Family::query()->withoutGlobalScopes()->where('school_id', $id)->pluck('id');
Classroom::query()->withoutGlobalScope(BelongsToSchoolYearScope::class)->where(...);
```

Cas légitimes présents dans le code :
- `SchoolContext::userHasAccess()` — le contexte n'est pas encore posé, on ne peut pas se scoper soi-même.
- `UserController::formatRoles()` — `/users/{id}/roles` est appelé **avant** qu'une école soit sélectionnée.
- `SchoolYearController::classroomsForReconduction()` — liste les classes d'une année **archivée**.
- `PaiementController::facture()` — résout la `SchoolYear` du header pour comparer son `school_id`.

**Chaque `withoutGlobalScopes()` doit être suivi d'un filtre explicite `where('school_id', …)`.** Un bypass sans re-filtrage = fuite cross-tenant.

## 6. Le `school_id` ne vient JAMAIS du client

```php
// ✗ INTERDIT
Classroom::create(['school_id' => $request->school_id, ...]);

// ✓ le trait s'en charge
Classroom::create(['name' => ..., 'cursus_id' => ...]);
```

`school_id` / `school_year_id` / `created_by` / `updated_by` sont **hors `$fillable`** : même si le front les envoie (ce que font encore `services/classe.js` et `services/cursus.js`), ils sont ignorés. C'est le comportement voulu.

Pour les **id de ressources liées** venant du payload (`cursus_id`, `level_id`, `classroom_id`, `family_id`, `teacher_id`), la règle `exists` ne suffit pas :

```php
// ✓ contrainte d'appartenance dans la règle elle-même
'cursus_id' => ['required', Rule::exists('cursus', 'id')->where('school_id', $schoolId)],
```
(voir `StoreClassroomRequest` / `UpdateClassroomRequest` pour la version complète, y compris `teacher_id` validé contre `user_roles`.)

## 7. Vérifier l'appartenance dans un contrôleur

Quand tu reçois un modèle par Route Model Binding, le global scope l'a déjà filtré **si le modèle porte le trait**. Sinon, check explicite :

```php
if ($classroom->school_id !== currentSchoolId()) {
    return response()->json(['message' => 'Accès refusé'], 403);
}
```

Pour les familles, utiliser le helper partagé :
```php
if (!FamilyController::callerCanAccessFamily($family)) { … 403 … }
```
(super-admin, OU staff `director|admin|registar` de l'école de la famille, OU membre de la famille.)

## 8. Checklist quand tu écris une requête

- [ ] Le modèle porte-t-il `BelongsToSchool` ? Sinon → filtre manuel obligatoire.
- [ ] Le modèle porte-t-il `BelongsToSchoolYear` ? Sinon et si la donnée est annuelle → filtre manuel sur `school_year_id`.
- [ ] Suis-je hors HTTP ? → réinjecter le contexte + `finally` de restauration.
- [ ] Ai-je un `withoutGlobalScopes()` ? → un `where('school_id', …)` explicite juste après.
- [ ] Un id vient-il du payload ? → `Rule::exists(...)->where('school_id', $schoolId)` + re-filtre côté query.

---

**Voir aussi** : `db-schema` (quelles tables portent quoi) · `queues-jobs-notifications` (contexte hors HTTP) · `debug-api` (piège des scopes en tinker) · `annees-scolaires` (axe année)
