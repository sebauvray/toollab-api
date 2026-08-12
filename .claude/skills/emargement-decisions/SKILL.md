---
name: emargement-decisions
description: Émargement des présences (table attendances, matrice éditable côté professeur) et décisions de fin d'année (StudentYearOutcome, professeur principal, toggle outcomes_open, vues admin /classes/[id] et /decisions). Inclut la sémantique critique « état complet » des endpoints POST. À invoquer pour toute modification du suivi de classe, des présences ou des décisions.
---

# Émargement & décisions de fin d'année

## 1. Émargement — table `attendances`

```sql
attendances(student_id, classroom_id, school_id, school_year_id, date,
            status ENUM('present','absent_justifie','absent_non_justifie'),
            justification TEXT?, created_by, updated_by)
UNIQUE(student_id, classroom_id, date)
```
Modèle : `BelongsToSchool` + `BelongsToSchoolYear` + `TrackChangesTrait` (les 4 colonnes techniques sont hors `$fillable`).

**Une date = une séance.** Il n'y a pas de FK vers `class_schedules` : le créneau est déduit du jour de la semaine. Conséquence : deux cours le même jour dans la même classe ne peuvent pas être distingués.

`justification` n'est conservée que si `status === 'absent_justifie'` (vidée sinon, côté serveur).

## 2. ⚠ Sémantique « état complet » — LE point à ne jamais casser

`TeacherController::saveAttendance` **et** `TeacherController::saveOutcomes` ont la même sémantique :

> Le payload décrit **l'état complet** de la séance (resp. de la classe). Tout ce qui n'y figure pas est **supprimé**.

```php
'records'   => 'present|array',   // PAS required|min:1 — un tableau vide est légal
'decisions' => 'present|array',

// après l'upsert :
Attendance::where('classroom_id', …)->whereDate('date', $date)
    ->whereNotIn('student_id', $keptIds ?: [0])->delete();

StudentYearOutcome::where('classroom_id', …)->where('school_year_id', …)
    ->whereNotIn('student_id', $keptIds ?: [0])->delete();
```

Le front **doit** donc envoyer tous les élèves ayant une valeur non vide. C'est ce qui permet de « vider » une cellule (cycle → vide → save = suppression) et de retirer une décision (re-clic = toggle → exclue du payload → supprimée).

`?: [0]` est indispensable : `whereNotIn('student_id', [])` supprimerait tout... non — il ne filtrerait rien, donc supprimerait tout. Le `[0]` garantit un id impossible.

Ne convertis **jamais** ces règles en `required|min:1` et ne remplace pas le delete par un simple upsert : tu casserais l'effacement.

Distinct : `POST /api/teacher/classrooms/{id}/outcomes` (prof, sémantique état complet) ≠ tout futur endpoint de clôture admin.

## 3. Endpoints professeur

Tous sous `auth:sanctum` + `school` + `schoolyear`, gardés par `TeacherController::guardClassroom()` =
`ensureTeacher(schoolId)` (UserRole teacher+school) **ET** même école **ET** `class_schedules.teacher_id = auth()->id()`.

```
GET  /api/teacher/classrooms                          mes classes (+ is_main_teacher, student_count)
GET  /api/teacher/classrooms/{c}/students             élèves triés nom + outcomes + outcomes_open
                                                      + is_main_teacher + year_closed + schedules + cursus.progression
POST /api/teacher/classrooms/{c}/outcomes             {decisions:[…]}  (état complet)
GET  /api/teacher/classrooms/{c}/attendance?date=     une séance
GET  /api/teacher/classrooms/{c}/attendance-matrix    toutes les séances : {dates[], students[{attendance:{date:{status,justification}}}]}
POST /api/teacher/classrooms/{c}/attendance           {date, records:[…]}  (état complet)
GET  /api/teacher/schedules                           mon planning (ScheduleController::mySchedules)
```

⚠ Dans `myClassrooms`, la relation `schedules` est **filtrée sur le prof connecté**. Ne pas y appeler `effectiveMainTeacherId()` (le fallback se baserait sur des créneaux filtrés) — le contrôleur recalcule via une requête non filtrée :
```php
$mainTeacherId = $c->main_teacher_id
    ?? $c->schedules()->whereNotNull('teacher_id')->orderBy('id')->value('teacher_id');
```

Tri des élèves : **toujours par nom de famille**, côté serveur (`sortBy(mb_strtolower(last.' '.first))`), affiché « NOM Prénom ».

## 4. Décisions de fin d'année — `student_year_outcomes`

```sql
student_year_outcomes(student_id, school_year_id, classroom_id,
  outcome ENUM('passage','redoublement','exclusion','fin_cursus'),
  commentaire TEXT?, decided_by?, decided_at?)
UNIQUE(student_id, school_year_id, classroom_id) · INDEX(school_year_id, classroom_id)
```

- **La décision est portée par la CLASSE, pas par le cursus** : un élève dans 2 classes a 2 lignes.
- Le modèle n'a **aucun global scope** → scoper `school_year_id` et `classroom_id` explicitement, toujours.

### Les 3 verrous de saisie

```php
1. guardClassroom()                                  // prof de cette classe
2. $classroom->effectiveMainTeacherId() === auth()->id()   // 403 — PROFESSEUR PRINCIPAL SEUL
3. $year->isOpen()            → 409 « Année clôturée »
   $year->outcomes_open       → 409 « Saisie des décisions non activée »
```

### Règle cursus continu

Si `classroom->cursus->progression === 'continu'`, les décisions `passage` et `redoublement` sont **refusées en 422**. Le front masque ces deux colonnes (`availableOutcomes`) et affiche un bandeau bleu explicatif.

### Professeur principal

`classrooms.main_teacher_id` (FK users, **hors `$fillable`**), posé uniquement par `ClassroomController::syncMainTeacher()` après le diff des créneaux :
```php
$main = match(true) {
    $requestedId && $teacherIds->contains($requestedId)               => $requestedId,
    $classroom->main_teacher_id && $teacherIds->contains($cur)        => $cur,
    default                                                          => $teacherIds->first(),
};
```
`Classroom::effectiveMainTeacherId()` = `main_teacher_id` sinon **premier `class_schedules.teacher_id` par id** (fallback legacy/seed).
UI : select « Professeur principal » dans Add/UpdateClassModal, visible seulement si ≥ 2 profs distincts dans les créneaux ; chip ambre « Principal » sur les lignes de créneaux.
⚠ `services/classe.js` doit transporter `main_teacher_id` dans `createClass` ET `updateClass` (whitelist).

## 5. Vues admin (director/admin)

```
GET /api/admin/classrooms/{c}/suivi   ClassroomController::adminSuivi
  → {classroom:{…, schedules:[{day,start_time,end_time,teacher}]},
     dates:[], students:[{last_name, first_name, outcome, commentaire,
                          attendance:{date:{status, justification}}}]}

GET /api/admin/outcomes               ClassroomController::adminOutcomesOverview
  → {items:[{student_id, first_name, last_name, classroom_id, classroom_name,
             teacher, cursus, cursus_id, level, outcome, commentaire}]}
```
`teacher` = noms distincts des profs des créneaux de la classe (fallback `teacher_name`), précalculé via `$teacherByClassroom` (eager `schedules.teacher`, **pas de N+1**).

⚠ Un élève sans aucun émargement renvoie `attendance: []` (array PHP vide, pas `{}`). En JS `att[date]` → `undefined` → cellule « – ». Pas de bug, mais ne pas supposer un objet.

`getAdminClassrooms` renvoie en plus `decided_count` (nombre de `StudentYearOutcome` de l'année pour la classe).

## 6. UI — les règles de design validées (ne pas régresser)

### Liste `pages/professeur/classes/index.vue`
Grille de cartes `md:2 / lg:3 / xl:grid-cols-4`. Bandeau coloré par genre (`genderColors`, fallback `#6B7280`) portant le nom + un badge **« Prof principal »** en pill **blanc plein** (`bg-white text-gray-800 shadow-sm`) — le translucide `bg-white/25` a été jugé peu visible, et « Principal » seul pas assez parlant. Corps : cursus · niveau, « X élèves » (**sans l'effectif maximum**, demande explicite), puis les créneaux `Jour HH:MM–HH:MM`.
État vide : « Aucune classe ne vous est attribuée pour cette année. »

### Détail `pages/professeur/classes/[id].vue` — 3 onglets

- **Onglets** en soulignés (`border-b-2 border-default` sur l'actif), chip ambre « Professeur principal » à droite.
- **Émargement = matrice éditable** : élèves en lignes (colonne nom `sticky left-0`), séances en colonnes **groupées par mois**, taux de présence par élève (ambre si < 70 %), légende. **PAS de ligne ∑ de totaux** (retirée).
- **Édition = clic sur la cellule qui défile** : `'' → present → absent_justifie → absent_non_justifie → ''` (constante `CYCLE`).
- **Autosave** débouncée ~450 ms **par séance** (`autosaveDate`/`doSaveDate`), indicateur « Enregistrement… ». **Pas de bouton Enregistrer.**
- **Motif d'absence justifiée = popover ancré** à la cellule (`getBoundingClientRect`, `position: fixed`), commit au blur/Enter, **pastille ambre** en coin si rempli.
- Bouton « **+ Séance** » plein `bg-default` qui ouvre l'`<input type="date">` natif via `showPicker()` → ajoute une colonne vide.
- **Décisions = matrice 4 colonnes** (une colonne par décision + colonne Note) : clic sur la cellule de la colonne voulue, re-clic = vide. Autosave silencieuse, **pas d'indicateur, pas de bouton**. Note = popover ancré + pastille ambre.
- L'onglet Décisions est visible pour **tous** les profs dès que `outcomes_open` ; le non-principal a un bandeau ambre et la lecture seule.

### Côté admin (`pages/classes/[id].vue`) — lecture seule

**Ne PAS réutiliser la matrice 4 colonnes du prof** (jugée illogique en lecture seule, la note y est invisible). À la place :
- onglet **Émargement** : même matrice enrichie que le prof (mois, colonne figée, taux), **motif au survol via un `hoverTip` maison** (popover blanc bordure ambre, `fixed z-40 pointer-events-none`, clampé `window.innerWidth - 220`). **Jamais le `title` natif.**
- onglet **Décisions** : tableau **Élève · Décision · Note**, décision = **chip teinté unique**, note **en texte clair inline**.

> **Règle générale admin read-only** : afficher l'information (motifs, notes) directement. Le survol/popover est une affordance d'**édition**, réservée au professeur.

### Vue globale `/decisions`

Bandeau de pilotage dans **un conteneur unique** `bg-white rounded-2xl border` (aucune card-dans-card) : fraction `décidées/total` + chip santé (vert ≥ 90 %, ambre ≥ 50 %, rouge) + **barre segmentée** par décision (non cliquable) + « N restantes sur K classes ».
Puis **5 stat-chips outcome cliquables en multi-sélection** (`Set`, OU logique, inclut `none`) : actif = **aplat plein** `bg-{c}-600 text-white` + ✓ + `ring-2`, les autres passent à `opacity-40`. C'est **le seul cas** où un chip de décision est en aplat plein (état de filtre, pas label de statut).
Puis barre d'outils (recherche NFD insensible aux accents, selects Cursus/Classe dépendants, tri) puis **table triable** : Élève · Cursus · Niveau · Classe · Professeur · Décision · **Note en texte clair** (`truncate` + `:title`, jamais une icône).
Le toggle d'ouverture des décisions (`schoolYearService.toggleOutcomes`) vit **ici**, visible seulement si on consulte l'année active.

Bandeau + chips se calculent sur `searchedItems` (périmètre cursus/classe/recherche, **hors** dimension outcome) : cliquer un chip ne fait donc pas s'effondrer le cockpit.

Tri par défaut « À décider d'abord » : `none < redoublement < exclusion < fin_cursus < passage`. Tri Niveau **numérique**.

**À ne pas faire** (écartés en revue) : pulse/animation déco, `text-2xl+`, avatars ou liseré par ligne, accordéon, sticky `top-[Npx]` codé en dur, skeleton, barre de répartition cliquable.

## 7. Checklist

- [ ] Sémantique « état complet » préservée (`present|array` + delete des absents).
- [ ] Décisions : les 3 verrous (principal, année ouverte, `outcomes_open`) intacts.
- [ ] Cursus continu : `passage`/`redoublement` toujours refusés.
- [ ] `StudentYearOutcome` scopé à la main (`school_year_id` + `classroom_id`).
- [ ] Tri par nom de famille côté serveur.
- [ ] Admin read-only : info affichée en clair, jamais derrière un survol.
- [ ] Pas de N+1 sur `schedules.teacher` (eager + map précalculée).

---

**Voir aussi** : `classes-cursus` (prof principal) · `annees-scolaires` (outcomes_open) · `design-system` (matrices et popovers)
