---
name: planning-creneaux
description: Affichage des emplois du temps dans Toollab — composant ScheduleGrid (algorithme de placement des créneaux qui se chevauchent, échelle horaire, couleurs par genre), endpoints /api/schedules et /api/teacher/schedules, et pages planning. À invoquer pour modifier l'affichage d'un planning, ajouter un filtre ou changer l'échelle horaire.
---

# Planning & créneaux

## 1. Les données

Un créneau = une ligne `class_schedules` : `classroom_id`, `teacher_id`, `day` (enum `Lundi`…`Dimanche`), `start_time`, `end_time` (TIME), `teacher_name` (legacy).
Il n'y a **pas** d'exception ni de date : le planning est **hebdomadaire et récurrent**. Une séance d'émargement est identifiée par une **date**, sans FK vers le créneau (skill `emargement-decisions`).

## 2. Endpoints

```
GET /api/schedules?teacher_id=      ScheduleController::index      checkrole:director,admin
    → [{ id, day, start_time, end_time, teacher_name, teacher_id,
          teacher:{id,first_name,last_name},
          classroom:{id,name,gender,cursus_id,cursus_name} }]

GET /api/teacher/schedules          ScheduleController::mySchedules   (prof connecté)
    → [{ id, day, start_time, end_time, classroom:{id,name,gender,cursus_name} }]
```
Les deux filtrent par `whereHas('classroom', fn($q) => $q->where('school_id', $schoolId))` — `ClassSchedule` **n'a aucun global scope**, le filtrage école est donc manuel. `mySchedules` ajoute `where('teacher_id', auth()->id())`.

Le scope **année** s'applique indirectement : `Classroom` porte `BelongsToSchoolYear`, donc `whereHas('classroom')` ne remonte que les classes de l'année courante.

## 3. `components/schedule/ScheduleGrid.vue`

```vue
<ScheduleGrid :schedules="schedules" @select="openClassroom" />
```
Prop unique `schedules` (tableau brut de l'API), événement `select` avec le créneau cliqué.

### Constantes de rendu
```js
const DAYS = ['Lundi', …, 'Dimanche']
const START_HOUR = 8
const END_HOUR = 22
const QUARTER_PX = 14                       // hauteur d'un quart d'heure
const GENDER_COLORS = { Hommes:'#93C5FD', Femmes:'#FDA4AF', Enfants:'#FCD34D', Mixte:'#86EFAC' }
```
Hauteur totale = `(END_HOUR - START_HOUR) * 4 * QUARTER_PX`. Les mêmes couleurs de genre sont utilisées par `/classes` et `/classes/[id]` — **garder cette table synchronisée** entre les trois.

### Algorithme de placement (chevauchements)

`layoutDay(items)` :
1. convertit `start_time`/`end_time` en minutes ;
2. trie par début puis par fin ;
3. regroupe en **clusters** de créneaux qui se chevauchent (fenêtre glissante : tant que `events[j].startMin < clusterEnd`) ;
4. dans chaque cluster, affecte chaque événement à la **première colonne libre** (`colEnds`) ;
5. la largeur d'un créneau = `1 / nombre de colonnes du cluster`.

C'est un algorithme classique de calendrier : deux cours simultanés se partagent la largeur du jour, trois se partagent en tiers.

Positionnement final de chaque bloc :
```js
const BLOCK_GAP_PX = 2
top      = ((startMin - START_HOUR*60) / 15) * QUARTER_PX
height   = Math.max(rawHeight - BLOCK_GAP_PX, QUARTER_PX*2 - BLOCK_GAP_PX)   // hauteur mini = 30 min
leftPct  = (col / cols) * 100
widthPct = 100 / cols
```
La **hauteur minimale équivaut à 30 minutes** : un créneau plus court reste lisible mais déborde visuellement sur le suivant.
Chaque jour a une largeur de base `DAY_BASE_WIDTH = 140` px, élargie selon `maxConcurrentByDay` (nombre max de colonnes ce jour-là).

Si tu modifies le rendu, **ne casse pas ce calcul** — il est purement fonctionnel et validé visuellement.

### Limites connues
- Un créneau **avant 8 h ou après 22 h** est hors grille (positionnement négatif ou débordant). Élargir `START_HOUR`/`END_HOUR` si une école a des cours tôt/tard.
- Le dimanche est toujours affiché même si vide.
- Aucune gestion de fuseau : les heures sont des chaînes `H:i` locales.

## 4. Pages

### `/professeur/planning`
`teacherService.mySchedules()` → `ScheduleGrid`. Clic sur un créneau → `/professeur/classes/{id}`.
État vide : « Aucun créneau ne vous est attribué pour cette année. »

### `/professeurs` (director/admin) — 2 onglets

| Onglet | Contenu |
|---|---|
| **Liste** | professeurs (`userService.listTeachers()`), invitation d'un prof, édition (`EditTeacherModal`), retrait de l'école |
| **Planning** | `ScheduleGrid` alimenté par `scheduleService.listSchedules({ teacher_id })`, filtrable par professeur ; clic sur un créneau → `/classes/{id}` |

Fonctions notables :
- **Invitation d'un prof** = `staffService.createStaffUser({ …, role: 'teacher', school_id })` — c'est le **même** endpoint que la gestion du staff dans `/settings`, avec le rôle forcé.
- **`handleAddSelfAsTeacher()`** : un directeur/admin peut **s'auto-attribuer le rôle professeur** (cas du directeur qui enseigne). Après succès, la page **recharge ses rôles et diffuse l'événement** :
  ```js
  const currentRoles = getSchoolRoles(rolesResponse.roles?.schools || [], schoolId)
  writeCurrentSchoolRoles(currentRoles)
  window.dispatchEvent(new CustomEvent(SCHOOL_ROLES_UPDATED_EVENT, { detail: { schoolId, roles: currentRoles } }))
  ```
  C'est le **modèle à suivre** pour toute action qui modifie les rôles de l'utilisateur connecté.
- `EditTeacherModal` expose **`setErrors(errors, message)`** via `defineExpose` : la page y renvoie les erreurs 422 de l'API pour affichage dans la modale (avec `UserList.refreshUsers()`, c'est l'un des deux seuls `defineExpose` du projet).

⚠ Cette page passe `:items` au lieu de `:custom-items` à `BreadCrumb` (bug listé dans `bugs-connus`).

### Saisie des créneaux
Elle se fait dans **Add/UpdateClassModal** (pas dans une page planning) : liste bordée `divide-y`, jour via `SelectDay`, heures via **`<input type="time">` natif** (un select à pas fixes a été rejeté), prof via select, chip ambre « Principal ».
Le diff des créneaux et `syncMainTeacher` sont détaillés dans la skill `classes-cursus`.

## 5. Checklist

- [ ] Filtrage école explicite (`whereHas('classroom')`) — `ClassSchedule` n'a pas de global scope.
- [ ] `GENDER_COLORS` cohérent avec `/classes` et `/classes/[id]`.
- [ ] Algorithme de clusters préservé si tu touches au rendu.
- [ ] Heures hors 8 h-22 h : élargir les constantes plutôt que bricoler le positionnement.
- [ ] Fallback `teacher_name` affiché quand `teacher_id` est null.

---

**Voir aussi** : `classes-cursus` (créneaux et prof principal) · `emargement-decisions` · `ui-components`
