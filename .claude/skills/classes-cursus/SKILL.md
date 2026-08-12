---
name: classes-cursus
description: Gestion pédagogique de Toollab — cursus (progression levels/continu, niveaux), classes (capacité, genre, créneaux, professeur principal), endpoints ClassroomController et CursusController, planning, et écrans /cursus, /classes, /professeurs. À invoquer pour modifier la structure pédagogique, les classes, les créneaux ou l'affectation des professeurs.
---

# Cursus, classes & créneaux

## 1. Modèle

```
Cursus (PERMANENT, hors année)
  name, progression ∈ {levels, continu}, levels_count
  └── CursusLevel (name, order)
        └── Classroom (ANNUELLE : school_year_id NOT NULL)
              name, type, size, gender, telegram_link, main_teacher_id
              └── ClassSchedule (day, start_time, end_time, teacher_id, teacher_name)
              └── StudentClassroom (inscriptions)
```

- **`Cursus` et `CursusLevel` ne sont PAS year-scopés** (choix délibéré : le référentiel traverse les années). Seule leur **tarification** est annuelle.
- `progression = 'continu'` ⇒ pas de niveaux, et **les décisions `passage`/`redoublement` sont interdites** (422 côté API, colonnes masquées côté prof).
- `gender` ∈ `Hommes | Femmes | Enfants | Mixte` (validé par FormRequest uniquement).
- `type` : champ libre **non exposé dans l'UI**. Vaut **`'Standard'`** pour toute classe créée via l'application (défaut posé à la fois par `services/classe.js` et `ClassroomController::store`), mais **`'Arabe'`/`'Coran'`** dans le seed de dev. Il sert uniquement à la règle de remplacement de `enroll` (même cursus **+ même type**).
- `size` est un **VARCHAR** en base, traité comme int en PHP.
- `telegram_link` : nom de colonne legacy — **l'UI dit « Lien du groupe de classe »**, sans jamais nommer de plateforme.

## 2. Endpoints

```
# Cursus — checkrole:director,admin
GET    /api/cursus              paginé (per_page ≤ 100) : id, name, progression, type FR, classCount, levels[]
POST   /api/cursus              crée le cursus + génère « Niveau 1..N » si progression=levels
GET    /api/cursus/{cursus}
PUT    /api/cursus/{cursus}     name, progression, levels[] (renommage des niveaux)
DELETE /api/cursus/{cursus}     ⚠ SUPPRIME les classes du cursus + leurs UserRole + les niveaux

# Classes
GET    /api/classrooms                 paginé, filtre ?cursus_id=
GET    /api/classrooms/{classroom}     détail (expose cursus_id, type, years pour la modale d'édition)
POST   /api/classrooms                 checkrole:director,admin
PUT    /api/classrooms/{classroom}     idem — diff des créneaux
DELETE /api/classrooms/{id}            idem

# Vue admin — checkrole:director,admin
GET    /api/admin/classrooms                    toutes les classes + élèves + decided_count (NON paginé)
GET    /api/admin/classrooms/export             .xlsx
GET    /api/admin/classrooms/{classroom}/suivi  émargement + décisions
DELETE /api/admin/classrooms/{c}/students/{s}   retire un élève de la classe
GET    /api/admin/outcomes                      vue d'ensemble des décisions

# Créneaux
GET    /api/schedules?teacher_id=      checkrole:director,admin
GET    /api/teacher/schedules          planning du prof connecté
```

⚠ `DELETE /api/cursus/{cursus}` est **destructeur en cascade** et il n'y a **pas de soft delete** : classes, rôles de classe et niveaux sont perdus définitivement. Toujours prévenir l'utilisateur.

### ⚠ Supprimer une classe : ce que les FK cascadent, et ce qu'elles NE cascadent PAS

`classroom_id` est contraint en **cascade** sur `class_schedules`, `student_classrooms`, `attendances`, `student_year_outcomes` → ces lignes disparaissent automatiquement.

Mais **`user_roles` est polymorphe, donc sans FK** : les `UserRole(student, roleable_type='classroom')` **ne sont pas cascadés**.
`CursusController::destroy` le gère explicitement ; **`ClassroomController::destroy` et `removeStudentFromClass` l'oublient** (bug `bugs-connus` A11).

Règle : **toute suppression touchant une classe ou une inscription doit nettoyer `user_roles` à la main** :
```php
UserRole::where('roleable_type', 'classroom')->where('roleable_id', $classroom->id)->delete();
```
Le même raisonnement vaut pour `roleable_type = 'family'` lors de la suppression d'une famille.

## 3. Créneaux — le diff dans `ClassroomController::update`

Le payload `schedules[]` décrit **l'état voulu** :
```
{id: 12, day, start_time, end_time, teacher_id, teacher_name}  → UPDATE
{id: 12, delete: true}                                          → DELETE
{day, start_time, end_time, teacher_id}                         → CREATE
```
Après traitement, les créneaux existants **absents** de `$updatedScheduleIds` sont supprimés.
Puis `syncMainTeacher()` est appelé (voir §4).

Validation : `day ∈ {Lundi…Dimanche}`, `start_time`/`end_time` en `H:i`, `end_time` **après** `start_time`, et `teacher_id` validé comme **professeur de cette école** :
```php
Rule::exists('user_roles', 'user_id')->where(fn($q) => $q
    ->where('roleable_type', 'school')->where('roleable_id', $schoolId)
    ->whereIn('role_id', Role::query()->where('slug', 'teacher')->pluck('id')))
```

`teacher_name` (texte libre) est un **champ legacy conservé comme fallback d'affichage** quand `teacher_id` est null. Ne pas le supprimer.

## 4. Professeur principal

`classrooms.main_teacher_id` — **hors `$fillable`**, posé uniquement par `ClassroomController::syncMainTeacher($classroom, $requestedId)` :
```php
$main = match (true) {
    $requestedId && $teacherIds->contains($requestedId)                   => $requestedId,
    $classroom->main_teacher_id && $teacherIds->contains($courant)        => $courant,
    default                                                              => $teacherIds->first(),
};
```
(`$teacherIds` = `teacher_id` distincts des créneaux, ordonnés par `id`.)

Comportement : par défaut le **premier prof ajouté** ; commutable via `main_teacher_id` dans le payload ; **ignoré** s'il n'est pas parmi les profs des créneaux ; si le principal est retiré des créneaux, retombe sur le premier restant.

`Classroom::effectiveMainTeacherId()` = `main_teacher_id`, sinon **premier `class_schedules.teacher_id` par id** (fallback legacy/seed).
⚠ Ne pas l'appeler sur les modèles de `TeacherController::myClassrooms` : leur relation `schedules` y est **filtrée sur le prof connecté**.

Seul le professeur principal peut **saisir** les décisions (403 sinon) — skill `emargement-decisions`.

UI : select « Professeur principal » dans Add/UpdateClassModal, visible seulement si ≥ 2 profs distincts, en `drop-up`. Chip ambre « Principal » sur les lignes de créneaux.
⚠ **`services/classe.js` reconstruit le payload en whitelist** : `main_teacher_id` doit rester présent dans `createClass` **et** `updateClass`.

## 5. Capacité

```php
Classroom::$appends = ['student_count', 'available_spots'];   // 1 COUNT par ligne listée
Classroom::isFull()          // student_count >= size
```
Pour une liste, préférer `withCount('activeStudents')` (→ `active_students_count`). `exportClassrooms` le fait déjà.
`enroll` vérifie `isFull()` **dans une transaction avec `lockForUpdate()`** sur la classe.

## 6. Écrans

### `/cursus` (`admin-director`)
Liste paginée + création. Le type est affiché en clair (« Par niveaux » / « Continu »).

### `/cursus/[id]` — ⚠ **pas de middleware `admin-director`**
Détail du cursus + ses classes + création/édition de classes. Les endpoints sous-jacents sont gardés côté API ; si tu ajoutes une action sensible sur cette page, gate-la explicitement.

### `/classes` (`admin-director`)
**Toggle de vue à icônes** (grille / liste, actif `bg-default`, mémorisé dans `localStorage.classes_view`) :
- **détaillée** : cartes par cursus+niveau, bandeau coloré par genre (`Hommes #93C5FD`, `Femmes #FDA4AF`, `Enfants #FCD34D`, `Mixte #86EFAC`), liste des élèves avec retrait au survol ;
- **liste** : lignes sobres avec accent `border-l-4` couleur genre, effectif, avancement des décisions (`decided_count/student_count`), boutons Émargement / Décisions.

En tête : `ExportButton` + bouton « **Vue d'ensemble des décisions** » vers `/decisions` (cette page **n'est plus** dans la navigation latérale).
État vide : encart « Aucune classe pour le moment » → « Aller aux cursus ».

### `/classes/[id]` (`admin-director`)
Hub de suivi, structuré ainsi :
- **Carte d'identité** `bg-white rounded-2xl border border-l-4` (accent = couleur de genre) : avatar `w-11 h-11 rounded-xl` avec les **2 premières lettres** du nom, `<h1>` du nom de la classe, sous-ligne `cursus · niveau · genre · N élèves`, puis la liste des créneaux avec le professeur (ou « **Prof. non assigné** » en italique gris) — visible sur les deux onglets ;
- bouton **« Modifier »** (secondaire, avec état « Chargement… ») qui ouvre `UpdateClassModal` **sur place**, sans redirection ;
- onglets soulignés **Émargement / Décisions** (`?tab=decisions` pré-sélectionne).

⚠ Il n'y a **pas** de bandeau KPI (présence moyenne / séances émargées) : l'ancienne documentation en mentionnait un, il n'existe pas dans le code. Le seul compteur affiché est « Décisions de fin d'année · n/N décidés » en tête de l'onglet Décisions, et le taux de présence **par élève** dans la dernière colonne de la matrice.

### `/professeurs` (`admin-director`)
Liste des professeurs et de leurs créneaux (`/api/users/teachers` + `/api/schedules`).
⚠ Cette page passe `:items` au lieu de `:custom-items` à `BreadCrumb` — bug listé dans `bugs-connus`.

## 7. Checklist

- [ ] Cursus/niveaux restent **permanents** (aucun `school_year_id`).
- [ ] Classes et inscriptions restent **annuelles**.
- [ ] `teacher_id` validé comme prof de l'école courante.
- [ ] Fallback `teacher_name` préservé.
- [ ] `syncMainTeacher` appelé après toute modification de créneaux.
- [ ] Nouveau champ classe ⇒ ajouté dans `createClass` ET `updateClass` du service front.
- [ ] Capacité vérifiée sous verrou lors de l'inscription.
- [ ] Suppression de cursus : prévenir de la cascade définitive.

---

**Voir aussi** : `emargement-decisions` · `planning-creneaux` · `annees-scolaires` (reconduction) · `modals` (Add/UpdateClassModal)
