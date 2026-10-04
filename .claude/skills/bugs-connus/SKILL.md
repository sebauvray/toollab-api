---
name: bugs-connus
description: Inventaire vérifié des bugs, code mort, dette technique et incohérences de Toollab (API et front), avec l'emplacement exact et la conséquence réelle de chacun. À invoquer avant de « corriger » un comportement bizarre, avant un refactor, ou quand quelque chose ne marche pas comme attendu.
---

# Bugs connus, code mort & dette technique

> Inventaire **vérifié dans le code** (pas hérité de doc obsolète). Si tu corriges une entrée, retire-la d'ici.

## A. Bugs actifs (impact utilisateur)

### A1. `ConfirmationModal` — props ignorées dans `pages/classes/index.vue`
Le composant attend `confirmButtonText` / `cancelButtonText` ; la page passe `confirm-text` / `cancel-text`.
→ Les boutons affichent les libellés par défaut « Confirmer » / « Annuler » au lieu de « Retirer ».
Fix : `:confirm-button-text="'Retirer'"`.

### A2. `BreadCrumb` — prop incorrecte dans `pages/professeurs/index.vue:241`
`:items="breadcrumbItems"` au lieu de `:custom-items`. Le prop est ignoré → fallback en mode auto qui découpe `route.path` et génère un lien vers `/professeurs` (qui existe, donc dégât limité, mais le fil est faux).
Fix : `:custom-items="breadcrumbItems"`.

### A3. Multi-chèques — état partiel possible
`pages/family/[id]/paiement.vue::addNewLigne` boucle et appelle `POST …/lignes` **une fois par chèque**. Chaque appel revalide indépendamment le plafond : si le lot dépasse le dû, les premiers chèques sont **déjà enregistrés** quand le 422 arrive. Aucun rollback.
Fix propre : endpoint batch transactionnel.

### A4. Front désinscrit par cursus, backend par cursus **+ type**
`family/[id]/classes.vue::toggleClass` retire toutes les classes du même cursus ; `StudentClassroomController::enroll` ne remplace que celles du même `cursus_id` **et** même `type`.

⚠ Nuance sur `classrooms.type` :
- **via l'UI**, il vaut **toujours `'Standard'`** (`services/classe.js` : `type: classData.type || 'Standard'`, et `ClassroomController::store` : `$request->type ?? 'Standard'`) — le champ n'est **exposé nulle part** dans les modales ;
- **dans le seed de dev**, il vaut **`'Arabe'` ou `'Coran'`** (`ToollabSeeder::createClasses`).

Dans les deux cas, `type` est **corrélé 1:1 au cursus**, donc la divergence n'est pas observable aujourd'hui. Elle le deviendrait si une école utilisait plusieurs types dans un même cursus.

### A4 bis. `family/[id]/classes.vue` — course entre deux `onMounted`
La page a **deux** `onMounted` : le premier charge famille/classes/inscriptions, le second charge les rôles pour calculer `hasAdminAccess`. Or `shouldShowClass()` (filtrage des classes par genre) **dépend de `hasAdminAccess`** : selon l'ordre d'arrivée des réponses, un directeur peut voir la liste filtrée par genre puis se compléter.
Aggravant : `loadUserSchools()` fait **un `getSchool(id)` par école** juste pour lire un rôle déjà disponible dans `localStorage` (`readActiveSchoolRoles()`).

### A4 ter. `available_only: true` envoyé mais ignoré
`family/[id]/classes.vue` passe `available_only: true` à `GET /api/classrooms`. **`ClassroomController::index` ne lit que `cursus_id` et `per_page`** → le paramètre est silencieusement ignoré ; le filtrage des classes pleines est fait côté front (`canClickClass`).

### A5. `SchoolController::getAllFamiliesInSchool` — endpoint vide
Route `GET /api/schools/{school}/families` déclarée, méthode au corps vide (`// Implementation as needed`) → renvoie `null`/204.
`schoolService.getSchoolFamilies()` existe côté front mais n'est appelé nulle part.

### A6. `services/school.js::deleteSchool` appelle une route inexistante
`SchoolController::destroy` existe mais **n'est routé nulle part** → `DELETE /api/schools/{id}` renvoie 404. La méthode front n'est appelée par aucune page (donc dormant).

### A7. `users.access` / `schools.access` jamais vérifiés
`AuthController::login` n'inspecte ni l'un ni l'autre. La feature « verrouiller l'accès d'une école » **n'existe pas** malgré l'existence des colonnes et du champ dans les formulaires super-admin.

### A8. Limite d'upload réelle = 1 Mo, alors que l'API en annonce 10
`FamilyImportController::import` valide `file|max:10240` (**10 Mo**), mais :
- `docker/nginx/{dev,prod}.conf.template` ne définissent **aucun `client_max_body_size`** → défaut nginx **1 Mo** ;
- `docker/php/Dockerfile` ne pose **aucun php.ini** → défauts PHP `upload_max_filesize = 2M`, `post_max_size = 8M`.

**Plafond effectif : 1 Mo** (nginx tranche en premier). Un fichier de 3 Mo renvoie un **413 avec une page HTML nginx** que le front ne sait pas parser → message d'erreur incompréhensible pour l'utilisateur.
Même problème potentiel sur l'upload de logo d'école (validé `max:2048`, soit 2 Mo).
Fix : `client_max_body_size 12m;` dans les deux templates nginx **et** un `php.ini` avec `upload_max_filesize`/`post_max_size` cohérents.

### A9. ⚠ `retry_after` (90 s) < `timeout` de `ProcessFamilyImportJob` (300 s) → **double exécution**

```php
config/queue.php : 'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)   // non défini dans .env → 90 s
app/Jobs/ProcessFamilyImportJob.php : public int $timeout = 300;
```
Règle Laravel : **`retry_after` doit être strictement supérieur au temps d'exécution le plus long de n'importe quel job.** Ici, un import qui dépasse **90 secondes** est considéré comme abandonné par la queue et **repris par un autre worker pendant qu'il tourne encore**. En production, **8 workers** tournent en parallèle : le risque est réel.

Conséquence observable : l'import réussit (1ʳᵉ exécution) puis la 2ᵉ exécution échoue sur `validateNoExistingStudents` (« Cet élève existe déjà ») et **écrase le statut en `failed`** — l'utilisateur voit un échec alors que les données ont bien été importées.

Fix : `DB_QUEUE_RETRY_AFTER=600` dans le `.env` (prod **et** dev), ou baisser le `timeout` du job sous 90 s.

### A10. `family/[id]/index.vue` — plante sur une famille sans responsable
```js
const breadcrumbItems = computed(() => [
  { name: 'Familles', path: '/family' },
  { name: family.value.responsibles[0].first_name + ' ' + …, path: null },   // ⚠ pas de garde
])
```
L'API prévoit explicitement le cas « Sans responsable » (`FamilyController::index`), mais ce computed lève une **`TypeError`** au rendu si `responsibles` est vide. Fix : `family.value.responsibles?.[0]`.

### A11. ⚠ `UserRole(student, classroom)` orphelins — deux chemins de suppression incomplets

`user_roles` est **polymorphe, donc sans clé étrangère** : rien n'est cascadé automatiquement quand une classe ou une inscription disparaît. Trois chemins de suppression existent, et **deux oublient le nettoyage** :

| Chemin | Supprime `StudentClassroom` | Supprime `UserRole(student, classroom)` |
|---|---|---|
| `StudentClassroomController::unenroll` | ✔ | ✔ |
| `StudentClassroomController::enroll` (remplacement) | ✔ | ✔ |
| **`ClassroomController::removeStudentFromClass`** | ✔ | **✖ oublié** |
| **`ClassroomController::destroy`** | ✔ (cascade FK) | **✖ oublié** |
| `CursusController::destroy` | ✔ (cascade FK) | ✔ (nettoyage explicite avant `delete()`) |

Conséquence : des lignes `user_roles` pointant vers des `roleable_id` de classes disparues s'accumulent. Elles faussent tout comptage de rôles et pourraient « ressusciter » si un id était réutilisé.
`ClassroomController::destroy` n'est en plus **pas dans une transaction** (contrairement à `CursusController::destroy`).

Fix : reprendre dans les deux méthodes le nettoyage de `CursusController::destroy` :
```php
UserRole::where('roleable_type', 'classroom')->where('roleable_id', $classroom->id)->delete();
```
Détection des orphelins existants :
```sql
SELECT ur.* FROM user_roles ur
LEFT JOIN classrooms c ON c.id = ur.roleable_id
WHERE ur.roleable_type = 'classroom' AND c.id IS NULL;
```

### A12. ⚠ `FamilyController::deleteStudent` supprime TOUS les rôles du `User`, toutes écoles confondues

```php
DB::table('user_infos')->where('user_id', $student->id)->delete();
DB::table('user_roles')->where('user_id', $student->id)->delete();   // ⚠ AUCUN filtre sur la famille
$student->delete();                                                   // ⚠ supprime le User entier
```
Le gate `ensureMemberOfFamily($student, $family, 'student')` vérifie bien que la cible est élève **de cette famille**, mais la suppression, elle, n'est **pas scopée** : si la même personne est aussi responsable d'une autre famille (possible via `POST /families/{id}/responsibles`, qui rattache un `User` existant) ou membre d'une autre école, **tout est détruit**.

Atténuation actuelle : l'UI masque le bouton Supprimer quand `student.is_responsible` — mais uniquement pour **la même** famille.

Fix : restreindre la suppression au contexte, et ne supprimer le `User` que s'il n'a plus aucun autre rôle :
```php
UserRole::where('user_id', $student->id)->where('roleable_type','family')->where('roleable_id',$family->id)->delete();
if (!UserRole::where('user_id', $student->id)->exists()) { $student->infos()->delete(); $student->delete(); }
```

### A13. Le CSS des écrans d'auth est dupliqué dans 4 fichiers
`login.vue`, `set-password.vue`, `reset-password.vue`, `forgot-password.vue` répètent à l'identique le même `<style scoped>` (`panel-mesh`, `panel-grid`, `letter-in`, `logo-pop`, `baseline-in`, `login-form`) et la même structure d'écran scindé.
→ Toute retouche du design d'auth doit être faite **4 fois**, sinon les pages divergent. (Contrairement à ce qu'indiquait l'ancienne doc, `forgot-password` **est** aligné.)

### A14. `?per_page=0` et `?page=` (vide) renvoient un **500** sur toutes les listes paginées

Les 5 sites de pagination plafonnent sans borner en bas : `min((int) $request->input('per_page', 10), 100)`.
`(int) '0'`, `(int) ''` et `(int) 'abc'` valent tous **0** → `ceil($total / 0)` lève une **`DivisionByZeroError`** (PHP 8), y compris à l'intérieur de `LengthAwarePaginator` (`$this->lastPage = max((int) ceil($total / $perPage), 1)`).

| Endpoint | Ligne |
|---|---|
| `GET /api/classrooms` | `ClassroomController:27` |
| `GET /api/cursus` | `CursusController:20` |
| `GET /api/families` | `FamilyController:233` + `PaginationTrait::paginateQuery` |
| `GET /api/statistics/unpaid-families` | `StatisticsController:298` |
| `GET /api/statistics/payments` | `StatisticsController:470` |

Même famille de bug sur `page`, mais **seulement dans `StatisticsController`** (l. 296 et 469), seul endroit où il n'est **pas** casté :
```php
$page = $request->input('page', 1);      // reste une string
$offset = ($page - 1) * $perPage;        // '' - 1 → TypeError → 500
```

Non atteignable depuis l'UI (`useTablePerPage` verrouille 10/25/50/100 et `page` vient d'un compteur), donc **pas une faille exploitable** — mais c'est un 500 non logué (le handler global ne journalise que si `app.debug` est faux) sur une entrée utilisateur.
Fix : `max(1, min((int) …, 100))` pour `per_page`, `max(1, (int) …)` pour `page`, aux 5 endroits.

### A15. `DELETE /api/classrooms/{id}` renvoie **500 au lieu de 404**

```php
public function destroy($id) {
    try { $classroom = Classroom::findOrFail($id); … }
    catch (\Exception $e) { return response()->json([… 'Erreur lors de la suppression de la classe'], 500); }
}
```
Le `catch (\Exception)` intercepte la `ModelNotFoundException` de `findOrFail` : une classe inexistante — ou appartenant à **une autre école** (le global scope la masque) — produit un **500** au lieu du **404 `Ressource introuvable`** attendu, et **sans aucun log** (cf. C4 ter).

C'est aussi la seule route de classe déclarée `{id}` et non `{classroom}` (`routes/api.php:136`) : **pas de route model binding**, d'où le `findOrFail` manuel. Fix : passer à `{classroom}` + injection du modèle (le RMB s'exécute après `SchoolContext`, cf. `multi-tenant-scoping`), et laisser le handler global produire le 404.

### A16. `pages/cursus/[id].vue` n'a pas le middleware `admin-director`

`pages/cursus/index.vue` déclare `middleware: 'admin-director'` ; **la page de détail ne déclare que `layout` et `layoutData`**. Un `registar` qui atteint `/cursus/12` par URL charge donc l'écran, qui échoue ensuite en **403** sur `GET /api/cursus/{cursus}` (route en `checkrole:director,admin`).
Pas une faille — l'API protège — mais une incohérence de gating front : écran vide + erreur au lieu d'une redirection propre vers `/`. Seule page applicative dans ce cas.

### A17. `DELETE /api/users/{user}` échoue par construction (FK RESTRICT)

```php
public function destroy(User $user) {
    …gates OK…
    $user->delete();                    // ⚠ aucune purge préalable
    return response()->json(null, 204);
}
```
`user_roles.user_id` est `->constrained('users')` **sans `onDelete`** → RESTRICT (défaut MariaDB). Comme tout utilisateur créé par l'application porte au moins un `user_role`, la suppression viole la contrainte → `SQLSTATE 23000` → **500**. Idem si la personne a enregistré un règlement (`paiements.created_by`, RESTRICT) ou est professeur d'un créneau (`class_schedules.teacher_id`, `restrictOnDelete()`).

Les gates, eux, sont corrects (`canManageUser` + refus sur super-admin). C'est la suppression qui est incomplète : il manque la purge que fait `FamilyController::deleteStudent` (`user_infos` puis `user_roles`).

**Dormant** : `userService.deleteUser()` existe mais **n'est appelé par aucune page** — le retrait d'un membre passe par `POST /api/users/remove-from-school`, qui ne touche que les `user_roles`. Tableau complet des FK : `db-schema` § « Supprimer un User ».

## B. Code mort (à ne pas étendre, à supprimer quand on passe dessus)

| Élément | État |
|---|---|
| `app/Http/Middleware/ApiResponseMiddleware.php` | jamais enregistré dans `bootstrap/app.php` |
| `ClassroomController::addStudent()` / `removeStudent()` | aucune route ne pointe dessus (l'inscription passe par `StudentClassroomController`) |
| `SchoolController::destroy()` | non routé |
| `UserController::index()` | `GET /api/users` pointe sur `getAllUsersWithRoles`, pas sur `index` |
| `TarificationController::getUserSchoolId()` / `isDirector()` | plus appelés ; filtrent par **nom FR** (`'Directeur'`) — **ne pas imiter** |
| `FamilyController::getUserClassroom()` | privé, jamais appelé, et buggé (`$userRole->name` n'existe pas) |
| `components/tarification/RecapitulatifTarifs.vue` | importé nulle part ; sa structure de données (`prix_base`, `reductions[]`, `prix_final`) ne correspond pas au service (`tarif_base`, `reduction_familiale`, `tarif_final`) |
| `services/user.js::getUsers()` | jamais appelé |
| `components/ui/LoadingScreen.vue` | importé nulle part — pendant que le même spinner est réécrit à la main dans **18 fichiers** |
| `components/UserDropdown.vue` | **plus référencé nulle part** depuis que `layouts/admin.vue` a son menu compte inline (`030e8e0`). ⚠ L'ancienne doc demandait de le conserver « pour `layouts/admin.vue` » — **c'était faux** |
| `components/form/InputCross.vue` | plus importé (servait à l'édition des niveaux de cursus) |
| `pages/contact.vue` | page marketing **non fonctionnelle** : les deux `InputText` n'ont **aucun `v-model`**, le bouton « submit » n'a **aucun handler** → le formulaire ne fait rien. Elle n'est plus atteignable depuis l'app (le lien « Contactez-nous » a été retiré du login) tout en restant dans les routes publiques d'`auth.global.js`. Style hors charte (`text-[32px]`, `rounded-3xl`, `shadow-xl`) |
| `components/Icons/{Dots,User-TLB,Monnaie-TLB,Card-TLB}.vue` | jamais importés |
| `database/seeders/UserSeeder.php`, `SchoolSeeder.php` | jamais appelés par un seeder parent |
| `toollab-api/api/` (collection **Bruno**) | squelette de mai 2025 **jamais rempli** : les 12 `.bru` ont `url:` vide, aucun body/header/auth, et `base_url: http://localhost` sans `:8000`. Aucune requête n'est exécutable → utiliser curl (`debug-api` §1) |
| `LOG_QUERIES=true` dans `.env` | **aucun code ne lit cette variable** (`app/`, `config/`, `bootstrap/`) — elle ne loggue rien |
| `pages/family/[id]/index.vue` : `handleEdit`, `handleSave`, `isEditing`, `contactInfo`, `editForm`, `isDropdownOpen`, `import axios` | ~60 lignes jamais référencées dans le template — vestiges de l'édition inline remplacée par `EditResponsableModal` |
| `Relation::enforceMorphMap` dans `AppServiceProvider` | commenté — d'où les `whereIn('roleable_type', ['school', School::class])` défensifs |
| `PaginationTrait::formatPaginatedResponse()` | **jamais appelé**. L'autre méthode du trait, `paginateQuery()`, n'a qu'**un seul** appelant (`FamilyController::index`) — le trait n'est pas le standard maison, contrairement à ce que sa présence suggère |
| `UserObserver` / `UserInfoObserver` — hooks `created/updated/deleted/restored/forceDeleted` | corps vides générés par `artisan`. Seul `updating()` fait quelque chose (cf. C10) |
| `middleware/auth.js` (front) | **no-op** : il ne fait quelque chose que si `to.meta.requiresAuth` est vrai, or **aucune page ne pose ce meta**. Déclaré uniquement par `select-school.vue`, qui est en réalité protégée par `auth.global.js`. (`guest.js`, lui, fonctionne : `meta.guest` est bien posé par les 4 pages d'auth) |
| `plugins/auth.js` → `$auth` | l'objet fourni (`isAuthenticated`, `getUser`) n'est **utilisé nulle part** ⚠ **mais ne pas supprimer le plugin** : il appelle `setupInterceptors()`, qui pose `baseURL` au runtime et branche les intercepteurs axios. Sans lui, tout le front tombe |

### B bis. Endpoints API maintenus mais consommés par AUCUN écran

Vérifié par recensement croisé `routes/api.php` ↔ `services/*.js` ↔ `pages/`+`components/` :

| Endpoint | Statut |
|---|---|
| `GET /api/users/by-context` | aucun service front ne l'appelle |
| `GET /api/users/classroom/{classroom}` | idem |
| `GET /api/schools/{school}/families` | service existe, jamais appelé — **et le contrôleur est vide** |
| `GET /api/statistics/enrollment-trends` | service existe, aucune page ne l'appelle |
| `GET /api/statistics/revenue-by-month` | idem |
| `POST /api/statistics/search-payments` | idem (les pages utilisent `searchPaymentsPaginated` → `GET /statistics/payments`) |
| `GET /api/teacher/classrooms/{c}/attendance` | remplacé par `…/attendance-matrix` ; le service existe encore |
| `DELETE /api/schools/{id}` | **la route n'existe pas** ; `schoolService.deleteSchool` pointe dans le vide |
| `GET /api/users` (`getAllUsersWithRoles`) | `userService.getUsers()` jamais appelé |
| `POST /api/users` (`UserController::store`) | `userService.createUser()` jamais appelé. Réservé au **super-admin** ; les élèves sont créés via `POST /families/{family}/students` |
| `DELETE /api/users/{user}` | `userService.deleteUser()` jamais appelé — **et cassé** (cf. A17) |

Méthodes de `services/user.js` sans appelant : `getUsers`, `createUser`, `deleteUser`. Celles réellement utilisées : `getUserRoles` (8 appels), `updateUser` (3), `listTeachers` (3), `searchStudents`, `updateUserInfo`, `changePassword`.

Méthodes de service front correspondantes, jamais appelées : `getUsersByContextAndRole`, `getClassroomUsers`, `getSchoolFamilies`, `getEnrollmentTrends`, `getRevenueByMonth`, `searchPayments`, `classroomAttendance`, `deleteSchool`, `getUsers`.

→ Ne pas y investir d'effort sans confirmer qu'ils sont réellement voulus. `enrollment-trends` et `revenue-by-month` semblent avoir été prévus pour des graphiques jamais branchés.

## C. Dette technique / points de charge

### C1. Pagination en mémoire
- `StatisticsController::unpaidFamilies` — `computeFamilyFinancials()` calcule l'attendu **de toutes les familles** de l'école, puis `array_slice`.
- `StatisticsController::payments` — bascule en mémoire dès qu'un filtre `exoneration_type` est actif.
- `FamilyController::index` — bascule en mémoire dès qu'on trie par `status` ou filtre par `payment_status` (le statut est calculé en PHP).
Le client ne reçoit que `per_page` lignes, mais le serveur traite tout l'effectif. Problème à plusieurs milliers de familles.

### C2 bis. Deux index manquants sur les tables les plus sollicitées
- **`user_roles(roleable_type, roleable_id)`** : l'index existant démarre par `user_id`, donc inutilisable pour le pattern de batch omniprésent `where('roleable_type','family')->whereIn('roleable_id', $ids)`.
- **`user_infos(user_id, key)`** : aucun index composite alors que la table est jointe/filtrée en permanence.

### C2 ter. `SchoolContext` charge tous les ids de l'école à chaque requête (non-staff)
`userHasAccess()` teste d'abord l'adhésion **directe** (rapide, sort immédiatement pour tout le staff). Mais pour un **responsable ou un élève**, il exécute :
```php
$familyIds    = Family::withoutGlobalScopes()->where('school_id', $schoolId)->pluck('id');       // TOUTES les familles
$classroomIds = Classroom::withoutGlobalScopes()->where('school_id', $schoolId)->pluck('id');    // TOUTES les classes
```
…à **chaque requête HTTP**. Borné (60 familles en démo), mais linéaire avec la taille de l'école. Optimisation possible : un `whereExists` corrélé au lieu de deux `pluck`.

### C2. N+1 potentiels
- `Classroom::$appends = ['student_count','available_spots']` → un COUNT par ligne listée. `ClassroomController::index` charge `activeStudents` en eager, ce qui atténue, mais `withCount` reste préférable.
- `UserRole` polymorphe sans `with('role','roleable')` → N+1.
- `TarifCalculatorService::calculerTotalFamille` fait un `User::find()` par élève, et est appelé **par famille** dans `computeFamilyFinancials`.

### C3. `Family::responsibles()` / `students()` non eager-loadables
Leur closure référence `$this->id` (null en eager loading) → `with('responsibles')` renvoie **vide**. Toujours batcher via `UserRole` (pattern de `computeFamilyFinancials`, `formatPaymentLignes`, `collectUnpaidFamilies`, `exportStudents`).

### C4. Incohérences de schéma
- `student_classrooms` : **pas de colonne `updated_by`** alors que `TrackChangesTrait` est appliqué (le modèle n'est jamais `update()`, donc latent).
- `classrooms.size` est un **VARCHAR** (migration d'origine), traité comme int en PHP.
- `roles.slug` n'a **pas** de contrainte unique (`RoleSeeder` est idempotent par `firstOrCreate`, ce qui compense).

### C4 bis. Aucune localisation FR des messages de validation
`APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, **pas de dossier `lang/`**. Toute règle de validation sans message custom renvoie un texte **anglais** dans une interface française. Contourné à la main par un `messages()` dans 7 des 9 FormRequests — donc facile à oublier sur un nouveau `$request->validate([...])`.

### C4 ter. ⚠ 13 blocs `catch` avalent l'exception **sans aucun log**

Les contrôleurs Api contiennent **31 `catch`** pour seulement **6 `Log::error`**. Trois contrôleurs ne loguent **rien** :

| Contrôleur | `catch` | log |
|---|---|---|
| `ClassroomController` | **7** | **0** |
| `CursusController` | 3 | 0 |
| `StudentClassroomController` | 3 | 0 |

Ces blocs renvoient eux-mêmes un 500 formaté (`['status'=>'error','message'=>'Erreur lors de …']`), donc **le handler global de `bootstrap/app.php` ne les voit jamais** et ne peut pas les logger non plus.

Conséquence : si une création de classe, une inscription ou une suppression de cursus échoue en production, on a « Une erreur est survenue » côté client et **strictement rien** côté serveur. Impossible à diagnostiquer.

Fix (à appliquer au passage dans ces fichiers) :
```php
} catch (\Exception $e) {
    Log::error('Classroom.store failed', ['exception' => $e, 'user_id' => auth()->id()]);
    return response()->json(['status' => 'error', 'message' => 'Une erreur est survenue'], 500);
}
```
(Et supprimer la clé `'error' => 'Une erreur est survenue'` redondante que ces catch ajoutent.)

### C4 quater. `StatisticsController` : 1 endpoint validé sur 8
Seul `searchPayments` appelle `$request->validate()`. Les 7 autres lisent `page`, `per_page`, `search`, `payment_type`, `banks`, `exoneration_type`, `filter` sans validation. Pas de **faille** (bindings Eloquent, pas d'injection), mais deux entrées y déclenchent un **500** au lieu d'un 422 propre — cf. **A14** : `?per_page=0` (division par zéro) et `?page=` vide (`TypeError` sur `('' - 1)`, `page` n'étant casté ni dans `unpaidFamilies` ni dans `payments`). Règles de validation prêtes à coller dans la skill `statistiques` §2 bis.

### C4 quinquies. Les deux observers écrivent sur `user_roles` **toutes écoles confondues**

`UserObserver::updating()` et `UserInfoObserver::updating()` (enregistrés dans `AppServiceProvider::boot`) font, dès qu'un `User`/`UserInfo` est modifié :

```php
UserRole::where('user_id', $user->id)->update(['updated_by' => auth()->id(), 'updated_at' => now()]);
```

`UserRole` **n'utilise pas** `BelongsToSchool` (vérifié : le modèle n'a que `TrackChangesTrait`) → aucun filtre d'école ne s'applique. Un directeur de l'école A qui corrige le prénom d'une personne également rattachée à l'école B **estampille les `user_roles` de l'école B** à son propre `user_id`.

Impact réel **faible** (métadonnées d'audit uniquement, aucune donnée métier touchée), mais : ça pollue la traçabilité, ça contourne `TrackChangesTrait` (mass update, pas d'événements modèle), et ça déclenche une écriture sur **N lignes** à chaque `update()` d'utilisateur.
Fix : restreindre aux rôles du contexte courant (`roleable_type='school'` + `roleable_id=currentSchoolId()`), ou supprimer ces observers dont la valeur est douteuse.

### C5. Codes HTTP discutables
- `PasswordResetController::sendResponse` renvoie **500** quand le reset échoue (token invalide ou expiré) au lieu d'un **422** → le front affiche « Une erreur serveur est survenue » pour une simple erreur d'utilisateur.
- `UpdateUserRequest` autorise la modification de l'**email** (`sometimes|email|unique`) sans re-vérification ni notification à l'ancienne adresse.

### C6. Incohérences de format d'API
Trois styles cohabitent : `{status,message,data}` (récent), retour brut (`login`, `schools`, `users`), et `{message, user}` (staff). **Tout nouvel endpoint utilise le format moderne**, sans casser les anciens (le front en dépend).

Clés JSON `details` des lignes de paiement : `nom_emetteur`/`justification` (API actuelle) vs `emetteur`/`motif` (données legacy prod). **Les fallbacks de lecture doivent être conservés** (`searchPayments`, `exportPayments`).

### C7. `ToollabSeeder::computeFamilyTotal()` duplique la tarification en dur
Les montants 270/240/210 (Arabe), 150 (Coran), 75 (multi-cursus) sont **codés en dur** dans cette méthode, alors qu'ils sont aussi posés par `createCursusEtTarification()`. Le seeder n'appelle pas `TarifCalculatorService`.
→ Changer un tarif de seed sans mettre à jour les deux endroits génère des paiements **supérieurs au montant dû** : le jeu de démo contredit alors le garde-fou 422 de l'app (familles en trop-perçu, statuts faux).

### C8. Commandes d'import CSV legacy
`import:families-csv` et `import:student-infos-csv` lisent un **chemin en dur** (`resources/data/EXP_ELEVE.csv`, versionné dans le dépôt), écrivent **sans contexte école** et ne sont pas fiablement idempotentes. Elles ont servi une fois à une reprise de données et expliquent deux traces encore visibles :
- les emails **`@corriger.com`** générés quand le CSV n'avait pas d'email exploitable (d'où le badge « À corriger » de la fiche famille) ;
- les 9 clés `user_infos` de fin d'année (`statut_scolaire`, `passage`, `redoublement`…), remplacées depuis par `student_year_outcomes` et nettoyables via `delete:student-info-keys`.

**Ne pas les relancer.** L'import supporté est `POST /api/families/import`.

### C8 bis. Données personnelles réelles versionnées
- `resources/data/EXP_ELEVE.csv` (~84 Ko) : export d'**élèves réels** (noms, dates de naissance, adresses, téléphones des responsables) commité dans le dépôt et présent dans l'historique git. Donnée personnelle au sens RGPD.
- `AlQalamSeeder` : **vraies adresses e-mail** de trois membres d'une école cliente + mot de passe `password` en dur.

### C9. Divers
- **Aucun soft delete** : tout DELETE est définitif et cascade (supprimer un cursus supprime ses classes ET les `UserRole` associés).
- `pages/family/[id]/classes.vue` a **deux blocs `onMounted`** distincts (l.350 et l.358) → risque théorique de course, sans effet observé.
- Hack `@corriger.com` : `family/[id]/index.vue:437` affiche un badge « À corriger » si l'email du responsable se termine par `@corriger.com` (marqueur de données importées incorrectes). Un flag DB serait plus propre.
- **Événements de modales non uniformes** : 11 modales émettent `save`, mais **`UpdateClassModal` émet `update`** — brancher `@save` dessus échoue silencieusement. Et **7 sur 12** passent un second argument `{resolve, reject}` que le parent doit consommer (sinon le formulaire reste bloqué en « envoi »). Contrairement à ce qu'indiquait l'ancienne doc, ce pattern promise n'est **pas** une exception d'`AddElevesModal` : c'est la majorité.
- Les 33 banques françaises sont **hardcodées** dans `pages/family/[id]/paiement.vue`. Si tu en ajoutes, extrais-les d'abord dans un util partagé.
- Les 3 modales responsable (`Add`, `AddNew`, `Edit`) sont quasi identiques → candidates à factorisation.
- CSS de checkbox custom **dupliqué à l'identique** (~40 lignes) dans `statistiques/cheques.vue` et `exonerations.vue`, avec un override `input[type="checkbox"] { appearance:none !important }` — **66 des 70 `!important` du projet sont là** (les 4 autres sont dans `main.css`). Cause : `main.css` restaure `appearance:auto` sur les cases, que ces pages re-neutralisent. Extraire un `form/InputCheckbox.vue` au 3ᵉ usage.
- **Palette de genre dupliquée dans 10 fichiers** (dont `components/form/SelectGenre.vue`, le seul composant réutilisable), et `Mixte` **manque** dans `annees-scolaires/index.vue` et `reconduire.vue` → une classe mixte y perd son accent coloré. (Deux palettes distinctes cohabitent : classe `Hommes/Femmes/Enfants/Mixte` et personne `M/F` — voir `design-system` §5 bis.)
- **`formatCurrency` réécrit à l'identique dans les 4 pages `/statistiques*`.**
- Seuls **12 fichiers** ont un `<style scoped>` : les 4 pages d'auth (CSS identique), `paiement.vue` (`.panel-in`), les 2 pages stats (checkboxes), `FlashMessage`, `StudentImport`, `InputNumber`, et 2 icônes. Tout le reste est en Tailwind pur — **rester dans cette logique**.
- Pages d'auth publiques (`set-password`, `reset-password`, `forgot-password`) utilisent `fetch` direct au lieu d'`apiClient` — sans intercepteurs. Toléré car publiques.
- **`app.vue` affiche un splash plein écran pendant 300 ms fixes** à chaque montage (`setTimeout` arbitraire, non corrélé à un chargement réel) — coût de perception gratuit sur toutes les navigations dures.
- **`FlashMessage` : le timer de fermeture n'est jamais annulé.** Le `watch` arme un `setTimeout(3000)` par message sans `clearTimeout` du précédent : deux messages rapprochés partagent la première échéance, donc le second peut disparaître au bout de quelques dizaines de ms. Visible sur les enchaînements (`pages/tarification/index.vue`, qui émet 14 flashs différents).
- **`components/settings/StudentImport.vue`, `UserList.vue`, `RoleCard.vue` ne servent qu'à `pages/settings/index.vue`** (660 lignes, 5 onglets). Cette page n'a **aucun middleware de route** : tout son gating est interne (`isDirector` / `canManageUsers`), ce qui est volontaire — les profs y ont accès pour l'onglet Profil/Mot de passe (`teacherAllowed`).

### A-entrypoint-dev. Expéditeur « `${APP_NAME}` » après redémarrage du conteneur API (dev)
`docker/php/entrypoint.sh` exporte le `.env` via `export $(grep -v '^#' .env | xargs)` : la valeur littérale `"${APP_NAME}"` de `MAIL_FROM_NAME` écrase celle déjà interpolée par Compose, puis `config:cache` la fige. Contournement : `php artisan config:clear`. Prod non concernée (`production-entrypoint.sh`). Non corrigé volontairement (fichier d'infra partagé).

### A-add-role. `POST /api/users/add-role` crée un rôle **non accepté** pour un membre déjà actif
`StaffController::addUserRole` fait `firstOrCreate` sans `accepted_at` : le rôle reste invisible et sans effet (`CheckRole`, `formatRoles`), contrairement à `createStaffUser` qui gère `alreadyAccepted`. **Dormant** : aucun écran n'appelle cet endpoint (constaté le 2026-10-04).

## D. Corrigé — ne pas « re-corriger »

- `POST /api/logout` ne révoque plus que le token courant (`currentAccessToken()`), plus tous les appareils (2026-10-04).
- Redirections de middleware au premier chargement (F5) : `utils/navigation.js::redirectTo()` recharge la page pendant l'hydratation au lieu d'un `navigateTo` routeur → plus de « Hydration mismatch » (registar sur `/statistiques`, prof sur `/family`, déconnecté sur `/settings`). **Tout nouveau middleware de route doit utiliser `redirectTo`, pas `navigateTo`.**
- Limite `throttle:` sur une route authentifiée : `ThrottleRequests` est désormais **après** `Authenticate` dans la priorité de `bootstrap/app.php` → limite par utilisateur, plus par IP.
- Rôles école soft-deleted hérités : purgés par `2026_10_04_100000_purge_soft_deleted_school_roles` (ils rendaient un rôle retiré visible sur une année archivée via `VisibleUntilYearClosedScope`).

- Retirer puis ré-attribuer un rôle staff ne fait plus de 500 (`unique_user_role_context` vs soft delete) : `StaffController` supprime les rôles école en `forceDelete()` et purge les reliquats (2026-10-02).
- `ToollabSeeder` crée le directeur et les professeurs **avec `accepted_at`** (avant : profs sans accès à l'école, directeur passant uniquement grâce au bypass super-admin).

- `POST /api/tarification/calculer` fonctionne (`calculerTotalFamille`, pas `calculerTarifsFamille`).
- Les stats n'ont plus de montants hardcodés ; elles délèguent à `TarifCalculatorService`.
- `TransactionPaiement` (modèle sans table) a été supprimé.
- `useFlashMessage.js` est correctement nommé (plus de typo `useFlasheMessage`).
- `student.year_infos` n'existe plus dans le front.
- Le multi-tenant **est** enforced (global scopes fail-closed) — l'ancienne doc affirmait le contraire.
- `pages/classes/index.vue` et les autres utilisent bien `:custom-items` (seul `professeurs/index.vue` reste, cf. A2).
- Il existe **des tests** (`FamilyImportServiceTest`, `StaffRolePermissionsTest`, `tests/schoolRoles.test.mjs`) — l'ancienne doc disait « tests vides ».

---

**Voir aussi** : `workflow-livraison` (ne pas re-signaler) · `recherche-codebase` (scripts d'audit) · `conventions-code`
