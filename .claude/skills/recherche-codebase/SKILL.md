---
name: recherche-codebase
description: Trouver rapidement quelque chose dans les deux dépôts Toollab — carte des fichiers par sujet, requêtes grep prêtes à l'emploi (endpoint, rôle, composant, clé localStorage, appelants d'une méthode) et méthode pour tracer une donnée de l'écran jusqu'à la base. À invoquer avant d'explorer à l'aveugle ou pour mesurer l'impact d'une modification.
---

# Trouver son chemin dans le code

## 1. Où regarder en premier, par sujet

| Sujet | Fichier de référence |
|---|---|
| Quels endpoints existent | `toollab-api/routes/api.php` (**source de vérité**) |
| Sécurité d'une route | même fichier — le **groupe de middleware** dit tout |
| Middlewares, exceptions, ordre | `toollab-api/bootstrap/app.php` |
| Isolation multi-tenant | `app/Traits/BelongsToSchool*.php`, `app/Models/Scopes/*`, `app/Support/helpers.php` |
| Rôles & permissions (back) | `app/Http/Middleware/CheckRole.php`, `app/Support/StaffRolePermissions.php` |
| Rôles & permissions (front) | `toollab-front/utils/schoolRoles.js` |
| Calcul des montants | `app/Services/TarifCalculatorService.php` |
| Agrégation paiement | `app/Services/PaiementService.php` |
| Client HTTP front | `toollab-front/services/api.js` |
| Navigation, écoles, rôles, années | `toollab-front/layouts/auth.vue` |
| Redirections & confinement prof | `toollab-front/middleware/auth.global.js` |
| Palette & polices | `toollab-front/tailwind.config.js`, `nuxt.config.ts` |
| Schéma réel | `database/migrations/` (lire dans l'ordre chronologique) |

## 2. Recettes grep

```bash
API=/Users/relhanti/Documents/Projets/toollab-new/toollab-api
FRONT=/Users/relhanti/Documents/Projets/toollab-new/toollab-front

# À quoi sert cet endpoint ?
grep -n "paiements" $API/routes/api.php
grep -rn "api/families/.*paiements" $FRONT/services

# Qui appelle cette méthode de contrôleur ?
grep -rn "getAdminClassrooms" $API/routes/api.php $API/app
grep -rn "getAdminClassrooms\|admin/classrooms" $FRONT/services $FRONT/pages

# Où ce composant est-il utilisé ?
grep -rln "ConfirmationModal" $FRONT/pages $FRONT/components

# Qui lit/écrit cette clé localStorage ?
grep -rn "current_school_active_role" $FRONT

# Où ce rôle est-il testé ?
grep -rn "'registar'" $API/app $API/routes
grep -rn "registar" $FRONT/utils $FRONT/middleware $FRONT/pages

# Toutes les routes d'un domaine
grep -n "school-years\|schoolyear" $API/routes/api.php

# Quels modèles portent tel trait ?
grep -rln "BelongsToSchoolYear" $API/app/Models

# Quelles pages ont un middleware ?
grep -rn -A4 "definePageMeta" $FRONT/pages | grep -B1 middleware

# Où une colonne est-elle utilisée ?
grep -rn "main_teacher_id" $API/app $API/database $FRONT

# Structure d'une table (toutes les migrations qui la touchent)
grep -rln "student_classrooms" $API/database/migrations
```

## 3. Tracer une donnée de l'écran à la base

Exemple : « d'où vient le nombre d'élèves affiché sur une carte de classe ? »

```
1. écran        grep -rn "student_count" $FRONT/pages
2. service      grep -rn "adminClassrooms\|admin/classrooms" $FRONT/services
3. route        grep -n "admin/classrooms" $API/routes/api.php
4. contrôleur   ClassroomController::getAdminClassrooms
5. modèle       Classroom::getStudentCountAttribute → activeStudents()->count()
6. table        student_classrooms WHERE status = 'active'
```
Cette traversée en 6 étapes fonctionne pour n'importe quelle donnée du produit.

## 4. Mesurer l'impact d'une modification

Avant de changer une signature, une clé de réponse ou un composant :

```bash
# 1. appelants directs
grep -rn "nomDeLaMethode" $API/app $API/routes

# 2. consommateurs front (par URL d'endpoint)
grep -rn "api/le/chemin" $FRONT/services

# 3. pages qui consomment le service
grep -rn "monService\." $FRONT/pages $FRONT/components

# 4. clés de réponse consommées
grep -rn "decided_count\|is_main_teacher" $FRONT
```
Cas piégeux connu : `getAdminClassrooms` alimente **`/classes` ET `/cursus/[id]`**.

## 4 bis. Audits automatiques (scripts prêts à copier)

### Méthodes de contrôleur non routées (code mort backend)
```bash
cd $API
for f in app/Http/Controllers/Api/*.php app/Http/Controllers/*.php; do
  c=$(basename "$f" .php)
  for m in $(grep -oE "public function [a-zA-Z]+" "$f" | sed 's/public function //' | grep -v '^__construct$'); do
    grep -q "${c}::class, '$m'" routes/api.php || echo "NON ROUTÉ: $c::$m"
  done
done
```
Résultat actuel : `ClassroomController::addStudent`, `::removeStudent`, `SchoolController::destroy`, `UserController::index`.

### Méthodes de service front jamais appelées
```bash
cd $FRONT
for m in $(grep -ohE "^\s+async [a-zA-Z]+\(" services/*.js | sed 's/.*async //;s/($//;s/(//'); do
  n=$(grep -rn "\.$m(" pages components layouts | wc -l)
  [ "$n" -eq 0 ] && echo "JAMAIS APPELÉ: $m"
done
```

### Composants jamais importés
```bash
cd $FRONT
for f in $(find components -name '*.vue'); do
  n=$(basename "$f" .vue)
  c=$(grep -rl "$n" pages components layouts app.vue --exclude="$f" | wc -l)
  [ "$c" -eq 0 ] && echo "JAMAIS IMPORTÉ: $f"
done
```

### Vérifier l'intégrité des skills
```bash
cd $API/.claude/skills
for d in */; do head -1 "$d/SKILL.md" | grep -q '^---$' || echo "FRONTMATTER KO: $d"; done
grep -c . */SKILL.md | tail -1
```

## 5. Repérer un pattern à imiter

```bash
# un contrôleur exemplaire (format moderne + gates + transaction)
sed -n '1,120p' $API/app/Http/Controllers/Api/SchoolYearController.php

# une page liste complète (recherche, tri, per-page, export gaté)
sed -n '1,180p' $FRONT/pages/family/index.vue

# une modale au gabarit standard
cat $FRONT/components/modals/AddCursusModal.vue

# un écran dense validé
sed -n '1,170p' $FRONT/pages/decisions/index.vue
```

## 6. Ordres de grandeur

```
API   ~11 400 lignes PHP applicatives — 18 contrôleurs, 22 modèles, 5 services,
                                        2 jobs, 6 notifications, 9 FormRequests, 50 migrations
FRONT ~19 300 lignes — 33 pages, 81 composants, 17 services, 5 composables, 5 middlewares
```
Les 5 plus gros fichiers (souvent ceux qu'on doit modifier) :
`pages/family/[id]/paiement.vue` (1127) · `FamilyController` (1121) · `pages/tarification/index.vue` (869) · `StatisticsController` (851) · `FamilyImportService` (714).

## 7. Réflexes

- **Toujours partir de `routes/api.php`** pour une question backend : le groupe de middleware répond déjà à « qui a le droit ».
- **Toujours partir du service front** pour une question « d'où vient cette donnée ? ».
- Un comportement bizarre ⇒ vérifier d'abord la skill `bugs-connus` : il y est peut-être déjà.
- Une donnée qui « n'existe pas » ⇒ vérifier la skill `db-schema` : la colonne existe-t-elle vraiment ?

---

**Voir aussi** : `bugs-connus` · `db-schema` · `ajouter-une-feature`
