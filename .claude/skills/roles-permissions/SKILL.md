---
name: roles-permissions
description: Système de rôles polymorphes Toollab (director, admin, registar, teacher, responsible, student), gates backend (CheckRole, canManageUser, callerCanAccessFamily, StaffRolePermissions), invitations et acceptation d'adhésion (accepted_at), et rôle actif unique côté front. À invoquer pour tout ajout de gate, changement de permission, création de staff, ou question « qui a le droit de… ».
---

# Rôles & permissions

## 1. Le modèle : rôles polymorphes contextuels

Table `user_roles(id, role_id, user_id, roleable_type, roleable_id, accepted_at, created_by, updated_by, timestamps)`
Unique : `unique_user_role_context(user_id, role_id, roleable_type, roleable_id)`

`roleable_type` ∈ `school` | `family` | `classroom` (morphMap déclaré dans `AppServiceProvider`, **non enforced** — des lignes legacy peuvent contenir `App\Models\School`, d'où les `whereIn('roleable_type', ['school', School::class])` dans `StaffController` et `InvitationController`).

Un même utilisateur porte donc **plusieurs rôles simultanés dans des contextes différents** : admin de l'école 1, responsable de la famille 12, élève de la classe 5.

## 2. Les 6 rôles

| slug | `name` (FR, affiché) | contexte | portée |
|---|---|---|---|
| `director` | Directeur | school | tout, y compris fiche établissement et gestion des admins |
| `admin` | Administrateur | school | administration sauf gestion des directeurs |
| `registar` | Responsable des inscriptions | school | **staff opérationnel** |
| `teacher` | Professeur | **school** | ses classes uniquement (lien via `class_schedules.teacher_id`) |
| `responsible` | Responsable | family | lecture de sa famille et de ses paiements |
| `student` | Élève | family **+** classroom | — (pas d'usage applicatif du login élève) |

**Le rôle `teacher` est porté par l'ÉCOLE, pas par la classe.** Le rattachement prof↔classe passe exclusivement par `class_schedules.teacher_id`. Ne jamais chercher un `UserRole(teacher, classroom)`.

## 3. Slug vs nom FR — la règle

**Backend : toujours filtrer par `slug`.**

```php
->whereHas('role', fn($q) => $q->whereIn('slug', ['director','admin','registar']))
```

Exception legacy à ne PAS imiter : `TarificationController::getUserSchoolId()` et `::isDirector()` filtrent encore par `name` FR (`'Directeur'`, `'Administrateur'`). Ces méthodes sont d'ailleurs **mortes** (les contrôleurs utilisent `currentSchoolId()`).

**Front : plus aucun nom FR en logique.** Tout passe par `utils/schoolRoles.js`, qui expose `ROLE_LABELS` (slug → libellé FR) et convertit les libellés legacy via `getRoleSlug()`.

Si tu renommes un rôle (le `name`), rien ne casse. Si tu changes un **slug**, propage dans : `RoleSeeder`, tous les `whereHas('role', …)` backend, `CheckRole` (routes `checkrole:…`), `StaffRolePermissions`, `utils/schoolRoles.js` (`ROLE_LABELS`, `ROLE_PRIORITY`), `utils/staffRoleCards.js`, `components/settings/UserList.vue` (`roleChip`/`roleDot`/`roleLabels`).

## 4. Les groupes de permission

```php
// staff opérationnel — familles, élèves, inscriptions, paiements
['director', 'admin', 'registar']

// pilotage — classes, cursus, tarification, statistiques, exports, années scolaires
['director', 'admin']
```

Où c'est appliqué :
- routes : `checkrole:director,admin,registar` sur `student-classrooms/{enroll,unenroll}` et les mutations `families/{family}/paiements/lignes` ; `checkrole:director,admin` sur cursus, classrooms (write), admin/classrooms, tarification, statistics, schedules, school-years (write), export/import familles.
- code : `FamilyController::callerCanAccessFamily()`, `FamilyController::index()` (`$isStaff`), `UserController::canTouchUserInCurrentSchool()`.

**Question à te poser à chaque nouveau gate** : « le registar doit-il passer ? »
→ inscriptions / familles / paiements / contacts : **oui**.
→ administration pédagogique ou financière de pilotage : **non**.

Les membres de famille (`responsible`, `student`) ont la **lecture** de leur famille et de leurs paiements, **aucune mutation**.

## 5. Les gates du backend — lequel utiliser

| Gate | Fichier | Règle |
|---|---|---|
| `checkrole:a,b` | `CheckRole` middleware | super-admin bypass ; sinon rôle **accepté** (`accepted_at` non null) sur l'école courante |
| `superadmin` | `SuperAdmin` middleware | `$user->is_super_admin` uniquement |
| `UserController::canManageUser($target)` | privé | super-admin, OU self, OU caller director\|admin d'une école commune |
| `UserController::canTouchUserInCurrentSchool($target)` | privé | plus permissif : + staff (`registar`) de l'école courante, + membre de la même famille |
| `UserController::callerHasSchoolRole($schoolId, $slugs)` | privé | pour les endpoints school-wide (index, getSchoolUsers, listTeachers…) |
| `FamilyController::callerCanAccessFamily($family)` | **public static** | super-admin, OU staff de l'école de la famille, OU membre de la famille. Réutilisé par `PaiementController` et `StudentClassroomController` |
| `StaffRolePermissions::canManage($callerSlugs, $targetSlug)` | `app/Support/` | director peut gérer `admin\|registar\|teacher` ; admin peut gérer `registar\|teacher` |
| `TeacherController::guardClassroom($classroom)` | privé | `ensureTeacher` (UserRole teacher+school) + même école + `class_schedules.teacher_id = auth()->id()` |

**Ne crée pas un nouveau gate si l'un de ceux-là couvre le besoin.** Il n'y a pas de Policy Laravel dans ce projet, et les `FormRequest::authorize()` retournent `true` sauf `StaffRequest`.

## 6. `is_super_admin` est DÉRIVÉ, pas stocké

```php
User::getIsSuperAdminAttribute(): bool
    => in_array($this->email, config('toollab.super_admin_emails'), true)
```
`config/toollab.php` lit l'env `SUPER_ADMIN_EMAILS` (CSV). Exposé dans `$appends` → présent dans la réponse `login`.

- Il n'y a **aucun « grant » admin en base**. Un compte devient super-admin dès que son email est dans l'env.
- **En prod la config est cachée** (`php artisan config:cache` dans `production-entrypoint.sh`) : modifier `SUPER_ADMIN_EMAILS` n'a aucun effet avant un `config:cache` ou un restart du conteneur. En dev (`APP_ENV=local`, pas de cache) c'est lu en direct.
- Bootstrap sur base vierge : `php artisan toollab:create-super-admin [email] --password=…` (voir skill `seeders-donnees-test`).

## 7. Invitation & acceptation d'adhésion (`accepted_at`)

C'est le mécanisme de confidentialité inter-écoles : **tant qu'une adhésion n'est pas acceptée, l'école ne voit pas le nom de l'utilisateur** (elle ne voit que l'email + le badge « En attente d'acceptation »).

```
Création staff (POST /api/users/create-staff, StaffRequest)
├── email inconnu     → User créé (first_name/last_name nullables !) + UserRole(accepted_at=null)
│                       + InvitationToken(school_id, 7j) + mail StaffInvitation
│                       → set-password?token=…&email=…  ⇒ activation = acceptation de CETTE école
├── email connu, école jamais acceptée → UserRole(accepted_at=null) + mail SchoolInvitationNotification
│                       → l'utilisateur accepte depuis le bandeau in-app
└── email connu, école déjà acceptée   → nouveaux rôles créés DÉJÀ acceptés + mail StaffRoleChangedNotification
```

Endpoints d'acceptation (hors contexte école, sous `auth:sanctum` seul) :
`GET /api/me/invitations` · `POST /api/me/invitations/accept` · `POST /api/me/invitations/decline`
UI : bandeau bleu en haut de `layouts/auth.vue`.

Conséquences à respecter :
- `SchoolContext::userHasAccess()` et `SchoolController::index()` exigent `whereNotNull('accepted_at')` pour l'adhésion **directe** (l'accès via famille/classe n'est pas soumis à acceptation).
- `CheckRole` exige `whereNotNull('accepted_at')`.
- `UserController::formatRoles()` filtre les rôles `school` sur `accepted_at` non null.
- `UserController::getSchoolUsers()` masque `first_name`/`last_name` (→ `null`) et renvoie `pending: true` tant qu'aucune adhésion de l'utilisateur pour cette école n'est acceptée.
- Les migrations existantes ont été backfillées `accepted_at = created_at` (les utilisateurs actifs restent visibles).

⚠ **Tous les gates ne filtrent pas sur `accepted_at`.** Le vérifient : `SchoolContext` (adhésion directe), `CheckRole`, `SchoolController::index`, `formatRoles`, `StaffController::createStaffUser`.
Ne le vérifient **pas** : `TeacherController::ensureTeacher`, `UserController::callerHasSchoolRole`/`canManageUser`, `FamilyController::callerCanAccessFamily`, `StaffController::canManageRole`.
→ **Tout nouveau gate reposant sur un `UserRole` de contexte `school` doit ajouter `->whereNotNull('accepted_at')`.** Détail du scénario d'exploitation dans la skill `securite-api` §6.

⚠ `users.first_name` / `last_name` sont **NULLABLES** depuis `2026_06_19_102552`. Tout affichage de nom doit tolérer `null` (`trim(($u->first_name ?? '').' '.($u->last_name ?? ''))`). `InvitationController::setPassword()` exige alors le renseignement du nom (`requires_profile`).

## 8. Gestion du staff — endpoints

| Endpoint | Rôle requis | Effet |
|---|---|---|
| `POST /api/users/create-staff` | `StaffRequest::authorize()` (director, ou admin pour registar/teacher) | crée/invite + attribue N rôles (`roles[]`, `role` = principal) |
| `POST /api/users/add-role` | `canManageRole` | ajoute un rôle à un user **déjà membre** de l'école (422 sinon) |
| `POST /api/users/remove-role` | `canManageRole` | retire un rôle ; **refuse de retirer son propre rôle** (422) |
| `POST /api/users/remove-from-school` | `StaffRolePermissions` sur **chaque** rôle cible | supprime toutes les adhésions école ; refuse sur soi-même |

Toutes ces routes vérifient `currentSchoolId() === $validated['school_id']`.
Chaque changement notifie l'utilisateur (`StaffRoleChangedNotification` : `added` | `removed` | `removed_from_school`).

⚠ **Les rôles école se suppriment en `forceDelete()`**, jamais `delete()` : `UserRole` porte `SoftDeletes` (prévu pour les liens famille/classe de la corbeille) mais `unique_user_role_context` n'inclut pas `deleted_at` → un reliquat soft-deleted fait échouer toute ré-attribution en 500. `StaffController::purgeTrashedSchoolRole()` absorbe les reliquats hérités avant chaque `firstOrCreate`.

## 8 bis. Passation de direction (`DirectorHandoverController` + `DirectorHandoverService`)

| Endpoint | Accès | Effet |
|---|---|---|
| `GET/POST /api/director-handover`, `POST …/{id}/resend`, `POST …/{id}/cancel` | `school` + `checkrole:director` **+ `isDirectorOf()` sans bypass super-admin** | consulter / lancer (email + `outgoing_role` ∈ admin\|registar\|none) / renvoyer (nouveau jeton, +7 j) / annuler |
| `POST /api/director-handover/{check,accept,decline}` | public, `throttle:token-check`, **seul le jeton compte** (jamais l'utilisateur connecté) | décrire / accepter / refuser |

Création et renvoi limités à 10/min **par directeur** ; seul l'initiateur peut renvoyer (403 sinon), tout directeur de l'école peut annuler. La notification d'invitation implémente `ShouldBeEncrypted` : le jeton brut ne doit jamais apparaître en clair dans `jobs`/`failed_jobs`. Règles : une seule passation `pending` par école (409 sinon ; une expirée est basculée `expired` à la création suivante) ; pas de passation vers soi-même ni vers un directeur existant (422) ; jeton stocké hashé (sha256), usage unique, 7 jours.
À l'acceptation (transaction + `lockForUpdate`) : l'émetteur doit encore être directeur (409 sinon) ; le destinataire reçoit `director` accepté, ses autres rôles école en attente sont acceptés, ses `admin`/`registar` sont retirés (redondants), `teacher` conservé ; l'émetteur perd `director` (et `admin`/`registar` hors rôle choisi) et reçoit le rôle choisi (`none` = aucun rôle d'administration). **Son rôle `teacher` est conservé par défaut**, y compris avec `none` ; il n'est retiré que si `remove_teacher_role` = true (choix proposé dans la modale uniquement quand il est professeur). Rôles famille et autres écoles intacts. L'e-mail de résultat liste les rôles restants.
Compte : si l'email n'a pas de compte, ou a un `InvitationToken` **et aucune adhésion acceptée nulle part** (compte invité jamais activé — un compte actif avec un vieux jeton n'est jamais touché), la page `/passation-direction` exige mot de passe (+ nom si vide) et révoque les tokens Sanctum ; sinon aucune donnée de compte n'est modifiée.

## 9. Côté front : le rôle ACTIF unique

Le point le plus important et le plus récent (`utils/schoolRoles.js`) :

- `current_school_roles` (localStorage) = tableau JSON de **tous** les slugs de l'utilisateur sur l'école courante.
- `current_school_active_role` = **le rôle sous lequel il navigue actuellement**, choisi dans le menu compte (sidebar → école → sous-liste des rôles).
- **Toutes les permissions front se calculent sur le rôle actif SEUL**, pas sur l'union : `readActiveSchoolRoles()` renvoie un tableau de 0 ou 1 élément.

```js
import { hasAnyRole, isTeacherOnly, readActiveSchoolRoles } from '~/utils/schoolRoles'

const canPilot = hasAnyRole(readActiveSchoolRoles(), ['director', 'admin'])
```

API du module :
`ROLE_LABELS` · `getRoleSlug(entry)` · `getSchoolRoles(entries, schoolId)` · `groupSchoolRoles(entries)` · `roleSlugs(roles)` · `hasAnyRole(roles, allowed)` · `isTeacherOnly(roles)` · `writeCurrentSchoolRoles(roles)` (garantit un actif valide) · `setActiveSchoolRole(slug)` · `readCurrentSchoolRoles()` · `readActiveSchoolRole()` · `readActiveSchoolRoles()` · `clearCurrentSchoolRoles()` · `SCHOOL_ROLES_UPDATED_EVENT`.

**Ne jamais lire `current_school_role` (singulier)** : clé legacy, seulement tolérée en fallback de lecture par `readCurrentSchoolRoles()`.

Quand les rôles changent en cours de session (`/settings`), émettre :
```js
window.dispatchEvent(new CustomEvent(SCHOOL_ROLES_UPDATED_EVENT, { detail: { schoolId, roles } }))
```
`layouts/auth.vue` écoute et revalide le rôle actif.

`middleware/admin-director.js` vérifie **doublement** : le rôle actif est director/admin **ET** le serveur confirme qu'il le détient réellement (`getUserRoles`). Un changement de rôle actif ne suffit donc pas à ouvrir un accès par URL.

## 10. Checklist « j'ajoute un gate »

- [ ] Slugs, jamais noms FR.
- [ ] Le registar doit-il passer ? (inscriptions/familles/paiements → oui)
- [ ] Route mutative → `checkrole:` + `school` (+ `schoolyear` si year-scopée).
- [ ] Un gate existant couvre-t-il déjà le besoin ? (§5)
- [ ] Le refus renvoie un message générique + `Log::warning` avec le contexte.
- [ ] Le front masque l'UI correspondante (`hasAnyRole(readActiveSchoolRoles(), …)`) — sans jamais s'y fier comme unique protection.

---

**Voir aussi** : `securite-api` (checklist) · `front-auth-roles` (rôle actif côté Nuxt) · `super-admin-ecoles` (invitations et création d'école) · `glossaire-metier`
