---
name: annees-scolaires
description: Gestion des années scolaires Toollab — création avec clonage de tarification, clôture, lecture seule (409 sur écriture), reconduction de classes, toggle d'ouverture des décisions, assistant front en 4 étapes et sélecteur d'année. À invoquer pour toute évolution touchant SchoolYear, le passage d'une année à l'autre ou le comportement « année archivée ».
---

# Années scolaires

## 1. Le modèle

```php
SchoolYear: id, school_id, label, opened_at, closed_at, is_active,
            outcomes_open, created_by, updated_by
UNIQUE(school_id, label)

$schoolYear->isOpen()   // is_active && closed_at === null
```

- **Une seule année active par école** = règle applicative (aucune contrainte DB). Elle est maintenue par `SchoolYearController::store()` qui clôture l'active avant d'en créer une nouvelle.
- `is_read_only` (exposé par l'API, pas une colonne) = `!is_active || closed_at !== null`.
- `outcomes_open` = interrupteur d'ouverture de la saisie des décisions de fin d'année (voir skill `emargement-decisions`).

## 2. Ce qui est year-scopé, ce qui ne l'est pas

| Year-scopé (`school_year_id` NOT NULL) | Permanent |
|---|---|
| `classrooms`, `student_classrooms`, `attendances` | `cursus`, `cursus_levels` |
| `tarifs`, `reduction_familiales`, `reduction_multi_cursuses` | `families`, `users`, `user_roles`, `user_infos` |
| `paiements` (UNIQUE `family_id`+`school_year_id`) | `comments`, `schools` |
| `student_year_outcomes` (colonne présente, **pas de scope automatique**) | |

Le choix « cursus permanent » est **délibéré** : le référentiel pédagogique traverse les années, seule sa tarification est annuelle.

## 3. Le middleware `schoolyear`

`App\Http\Middleware\SchoolYearContext` (monté **après** `SchoolContext`) :

1. Header `X-School-Year-Id` fourni → valide l'entier, vérifie qu'il appartient à l'école courante (403 sinon).
2. Absent → prend l'année **active** ; à défaut, la plus récente (`closed_at DESC, id DESC`) pour permettre la consultation de l'historique ; s'il n'y a **aucune** année → **409** `Aucune année scolaire n'est configurée pour cette école.`
3. Pose `current_school_year_id`.
4. Si l'année est read-only **et** que la méthode n'est pas GET/HEAD/OPTIONS → **409** :
```json
{"message":"Cette action est impossible sur une année scolaire clôturée.","read_only":true}
```

**Conséquence** : les GET restent autorisés sur une année archivée (consultation de l'historique). Une facture PDF reste donc téléchargeable sur une année clôturée.

## 4. Routes hors year-scope (volontairement)

```
school-years/*                        sinon impossible de lister les années archivées
classrooms/{classroom}/reconduct      on reconduit DEPUIS une année archivée
users/* (lecture + staff)             gestion de plateforme, pas de données pédagogiques
```

## 5. Endpoints

| Route | Rôle | Effet |
|---|---|---|
| `GET /api/school-years` | tout membre | liste + `is_read_only` + `outcomes_open` |
| `POST /api/school-years` | director/admin | clôture l'active (`is_active=false`, `closed_at=now()`) puis crée la nouvelle (active). 422 si le label existe déjà |
| `POST /api/school-years/{id}/close` | director/admin | clôture l'année active (409 si déjà clôturée). Force `outcomes_open=false` |
| `POST /api/school-years/{id}/outcomes-toggle` | director/admin | `{open:bool}`. 409 si l'année est clôturée |
| `GET /api/school-years/{id}/classrooms` | director/admin | classes de l'année (sans élèves) + `already_in_active_year` |
| `POST /api/classrooms/{id}/reconduct` | director/admin | clone la classe vers l'année active |

### Clonage de la tarification à la création

`store()` accepte `clone_from_year_id` et `clone_cursus_ids[]` :
```php
cloneTarification($sourceYearId, $targetYearId, ?array $cursusIds)
// tarifs / reduction_familiales      filtrés par cursus_id IN (…)
// reduction_multi_cursuses           filtré par cursus_beneficiaire_id IN (…)
// null = tout cloner (rétro-compat)
```
Copie en query builder brut, par `chunkById(200)`, avec `created_by = auth()->id()`. **Les classes ne sont PAS clonées** ici — c'est le rôle de la reconduction.

### Reconduction d'une classe

`reconductClassroom()` clone vers l'année **active** :
- champs métier (`name`, `years`, `type`, `size`, `cursus_id`, `level_id`, `gender`, `telegram_link`) ;
- `school_id` et `school_year_id` posés **explicitement** (le trait ne suffit pas : on écrit hors du contexte de l'année source) ;
- `main_teacher_id = $classroom->effectiveMainTeacherId()` ;
- **tous les `ClassSchedule`**, y compris `teacher_id` ET `teacher_name` ;
- **aucun élève**.
- 422 si la classe appartient déjà à l'année active ; 409 s'il n'y a pas d'année active.
- `size` et `name` peuvent être surchargés par le payload.

## 6. Front

**Composable `useSchoolYear()`** (état module-level partagé) :
```js
const { years, currentYear, activeYear, currentYearId, isReadOnly, load, switchTo, reset, create } = useSchoolYear()
```
- `load()` : charge la liste, restaure `localStorage.current_school_year_id` s'il est encore valide, sinon retombe sur l'année active.
- `switchTo(id)` : persiste **et fait `window.location.reload()`** (rechargement dur volontaire : tout l'écran est year-scopé).
- ⚠ `create()` du composable enchaîne `load()` + `switchTo()` → **il recharge la page**. Dans un flux multi-étapes (assistant), appeler `schoolYearService.create(...)` **directement** et ne faire `switchTo()` qu'à la toute fin.

**Sélecteur d'année** : dans la topbar de `layouts/auth.vue` uniquement (pas dans le menu compte). Son badge « archivée » pilote le bandeau ambre de lecture seule affiché sous la topbar.

**Assistant de création** (`pages/annees-scolaires/index.vue`) — stepper 4 étapes :
1. **Libellé** (seule étape s'il n'existe aucune année active = première année) ;
2. **Tarifs** : cursus ayant un tarif (`tarificationService.getCursusTarifs()` → `data.cursuses` filtré sur `c.tarif`), cochés par défaut → `clone_cursus_ids[]` ;
3. **Classes** : classes de l'année active (`classroomsForReconduction(activeYear.id)`) groupées par cursus, cochées par défaut. **Ignorer le flag `already_in_active_year`** qui vaut `true` partout avant la création ;
4. **Confirmation** : bandeau ambre « action irréversible » + récap.

Ordre d'exécution obligatoire (`finalizeCreate`) :
```js
const created = await schoolYearService.create({
  label,
  clone_from_year_id: (cursusIds.length && sourceYearId) ? sourceYearId : null,   // ⚠ null si AUCUN cursus coché
  clone_cursus_ids:   cursusIds.length ? cursusIds : undefined,
})
for (const id of classIds) {
  try { await schoolYearService.reconductClassroom(id, {}) } catch { /* doublon : on n'interrompt pas le lot */ }
}
if (created?.id) switchTo(created.id)          // en DERNIER (switchTo recharge la page)
```
- Décocher **tous** les tarifs annule le clonage complet (`clone_from_year_id: null`), pas seulement le filtrage.
- L'ancienne année est archivée par `create()` **avant** la boucle : les clones partent donc bien vers la nouvelle année active.
- Les échecs unitaires de reconduction sont avalés volontairement.

Autres éléments de la page :
- **Toggle `outcomes_open` présent ici aussi** (pill par année, `togglingOutcomesId` comme garde anti-double-clic), en plus de `/decisions`. Les deux appellent `schoolYearService.toggleOutcomes`.
- Tri : année active d'abord, puis `opened_at` décroissant.
- Bouton « Clôturer » sur l'année active (modale de confirmation).
- ⚠ Le `genderColors` local n'a que **3 entrées** (`Hommes`, `Femmes`, `Enfants`) — `Mixte` manque, contrairement aux autres écrans. Une classe mixte s'affiche donc sans couleur d'accent dans l'étape « Classes ».

### `pages/annees-scolaires/reconduire.vue` — page dédiée

Complémentaire de l'assistant, pour reconduire **après coup** :
- choix d'une **année source parmi les archivées** (`archivedYears`) ;
- classes groupées par **cursus → niveau** (`level.order` respecté, « Sans cursus » / « Sans niveau » en repli) ;
- **`selectableClassrooms` exclut celles déjà présentes dans l'année active** (`already_in_active_year`) — contrairement à l'assistant de création qui doit **ignorer ce flag** (il vaut `true` partout tant que la nouvelle année n'existe pas) ;
- **le nom et la capacité de chaque classe sont surchargeables** avant reconduction (`names`, `sizes` → payload `{name, size}` accepté par `POST /classrooms/{id}/reconduct`) ;
- sélection multiple avec « tout sélectionner », BreadCrumb en tête, **pas de `<h1>`**.

⚠ Ici aussi, le `genderColors` local omet `Mixte`.

**Désactiver l'UI en lecture seule** :
```js
const { isReadOnly } = useSchoolYear()
// :disabled="isReadOnly"  + :title="isReadOnly ? 'Année scolaire en lecture seule' : ''"
```
C'est un confort : la vraie protection est le 409 serveur.

## 7. Pièges

- Créer une année **clôture immédiatement** l'active. Irréversible via l'UI → toujours confirmer explicitement à l'utilisateur.
- Une école **sans** année (école créée hors `SchoolController::store`) provoque des 409 partout. `SchoolController::store` crée donc systématiquement une année active `AAAA-AAAA+1` (bascule au 1er septembre).
- `SchoolYear` porte `BelongsToSchool` : hors HTTP, la requêter exige `withoutGlobalScopes()` + `where('school_id', …)`.
- La reconduction ne copie **pas** les élèves : il n'existe **aucun** mécanisme de réinscription automatique (choix produit assumé).

---

**Voir aussi** : `multi-tenant-scoping` · `classes-cursus` (reconduction) · `emargement-decisions` (outcomes_open) · `tarification-paiements` (clonage)
