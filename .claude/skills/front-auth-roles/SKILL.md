---
name: front-auth-roles
description: Authentification et permissions côté Nuxt — useAuth, clés localStorage, système de rôle actif unique (utils/schoolRoles.js), sélection d'école et de rôle dans le menu compte, middlewares de route, confinement des professeurs et bandeau d'invitations. À invoquer pour toute question d'accès, de redirection ou d'affichage conditionnel côté front.
---

# Authentification & rôles côté front

## 1. Clés `localStorage` — le contrat complet

| Clé | Contenu | Écrite par |
|---|---|---|
| `auth.token` | token Sanctum | `services/auth.js::login` |
| `auth.user` | JSON du user (dont `is_super_admin`) | idem |
| `current_school_id` | id de l'école active | `layouts/auth.vue`, `select-school.vue` |
| `current_school_year_id` | id de l'année consultée | `useSchoolYear` |
| `current_school_roles` | **JSON : tableau de slugs** de tous les rôles sur l'école courante | `writeCurrentSchoolRoles()` |
| `current_school_active_role` | **le slug sous lequel l'utilisateur navigue** | `setActiveSchoolRole()` / `writeCurrentSchoolRoles()` |
| `classes_view` | `detailed` \| `list` | `pages/classes/index.vue` |
| `<table>_per_page` | 10/25/50/100 | `useTablePerPage(key)` |

Legacy : `current_school_role` (singulier) — **ne plus écrire**, seulement toléré en fallback de lecture.

Purge : au 401 (intercepteur axios), au `logout()`, et par `goToAdmin()` (qui retire école + rôles pour entrer en mode plateforme).

## 2. `useAuth()`

```js
const { user, isAuthenticated, isLoading, error, login, logout, initAuth, refreshUser } = useAuth()
```
`user` et `isAuthenticated` sont des **refs module-level partagées**, hydratées à l'import (`initAuth → syncFromStorage`).

⚠ **Se connecter DOIT passer par `useAuth().login()`**, jamais `authService.login()` directement : un login via le service + `router.push` (SPA, sans reload) laisse `user.value` à `null` jusqu'au prochain F5. Symptôme typique : `pages/index.vue` (barre de recherche gatée sur `user.value`) reste vide jusqu'à un rafraîchissement. Toute page gatée sur `useAuth().user` casse de la même façon.

## 3. Le rôle ACTIF unique — `utils/schoolRoles.js`

Un utilisateur peut cumuler plusieurs rôles sur une école (ex. `admin` + `teacher`). Le front ne raisonne **jamais sur l'union** : il applique le **rôle actif**, choisi dans le menu compte.

```js
import {
  ROLE_LABELS, getRoleSlug, getSchoolRoles, groupSchoolRoles, roleSlugs,
  hasAnyRole, isTeacherOnly,
  writeCurrentSchoolRoles, setActiveSchoolRole,
  readCurrentSchoolRoles, readActiveSchoolRole, readActiveSchoolRoles,
  clearCurrentSchoolRoles, SCHOOL_ROLES_UPDATED_EVENT,
} from '~/utils/schoolRoles'
```

| Fonction | Rôle |
|---|---|
| `getSchoolRoles(entries, schoolId)` | à partir de `getUserRoles().roles.schools`, renvoie `[{slug, label}]` **triés par priorité** (director > admin > registar > teacher > super-admin) |
| `groupSchoolRoles(entries)` | `{ schoolId: [{slug,label}] }` pour toutes les écoles |
| `writeCurrentSchoolRoles(roles)` | persiste la liste **et garantit un rôle actif valide** (conserve l'actif s'il est encore présent, sinon prend le premier) |
| `readActiveSchoolRoles()` | tableau de **0 ou 1** slug — c'est **ce qu'il faut passer aux contrôles de permission** |
| `hasAnyRole(roles, allowed)` | test d'appartenance |
| `isTeacherOnly(roles)` | exactement `['teacher']` → confinement professeur |
| `getRoleSlug(entry)` | tolère l'ancien format (`role` = libellé FR) et le convertit en slug |

**Pattern à utiliser dans une page :**
```js
const canPilot = hasAnyRole(readActiveSchoolRoles(), ['director', 'admin'])
```
**Jamais** de comparaison à un libellé français (`role === 'Directeur'`) : ce style a été entièrement supprimé.

## 4. Changer d'école ou de rôle

`layouts/auth.vue::enterSchool(school, slug = null)` :
```js
localStorage.setItem('current_school_id', String(school.id))
writeCurrentSchoolRoles(school.roles)
setActiveSchoolRole(targetRole)
resetYears()
window.location.reload()      // rechargement dur volontaire
```
No-op si (même école && même rôle). Le menu compte affiche la sous-liste des rôles **dépliée d'office pour l'école active**, et derrière un chevron pour les autres.

**Synchroniser après une modification de rôles en cours de session** (ex. `/settings`) :
```js
window.dispatchEvent(new CustomEvent(SCHOOL_ROLES_UPDATED_EVENT, { detail: { schoolId, roles } }))
```
`layouts/auth.vue` écoute, met à jour sa liste et **revalide le rôle actif** (qui peut avoir disparu).

## 5. Middlewares de route

| Fichier | Effet |
|---|---|
| `auth.global.js` | s'applique **partout**. Pages publiques : `/login`, `/contact`, `/forgot-password`, `/reset-password`, `/set-password`. Exige un token, puis une école (sauf `noSchoolNeeded` et `/admin/*`). Redirige un connecté hors de `/login`. Applique le **confinement professeur**. |
| `admin-director.js` | rôle actif ∈ director/admin **ET** confirmé côté serveur (`getUserRoles`) → un changement de rôle actif ne suffit pas à ouvrir un accès par URL. Sinon → `/`. Super-admin bypass. |
| `super-admin.js` | `is_super_admin` uniquement, sinon → `/`. |
| `guest.js` | optionnel par route ; agit si `meta.guest` est vrai — posé par les 4 pages d'auth. Redirige un connecté vers `/`. |
| `auth.js` | ⚠ **no-op** : conditionné à `meta.requiresAuth`, que **aucune page ne pose**. Déclaré par `select-school.vue` seule, qui est en fait protégée par `auth.global.js`. Ne pas s'en servir pour protéger un écran. |

### Confinement professeur
```js
const isTeacher = hasRoleCache && isTeacherOnly(readActiveSchoolRoles())
const teacherAllowed = to.path.startsWith('/professeur')
    || to.path === '/settings'
    || noSchoolNeeded.includes(to.path)
if (isAuthenticated && !isSuperAdmin && isTeacher && !teacherAllowed) return navigateTo('/professeur/classes')
```
⚠ **Tout nouvel écran destiné aux profs hors `/professeur/*` doit être ajouté à `teacherAllowed`**, sinon le prof est rebouclé. `/settings` y figure : la page masque déjà ses onglets admin via `v-if="isDirector"`.

La navigation latérale suit la même logique, via 3 drapeaux calculés sur le **rôle actif seul** dans `layouts/auth.vue` : `hasGeneralAccess` (non-prof) pour Accueil/Familles, `hasAdminAccess` pour Cursus/Classes/Professeurs/Tarification/Statistiques, `hasTeachingAccess` pour Mes classes/Mon planning.

⚠ Il n'existe **aucun drapeau « staff opérationnel »** : un `registar` — qui a pourtant tous les droits sur familles, inscriptions et paiements côté API — ne voit dans le menu que **Accueil** et **Familles**. Toute nouvelle page à lui ouvrir exige un drapeau supplémentaire. La liste des `<NavLink>` est **écrite à la main** (`layouts/auth.vue` l. 296-305) : créer un fichier dans `pages/` n'ajoute rien au menu. Détail et écrans sans entrée de menu : `nuxt-page` §2 bis.

`pages/index.vue` n'affiche la barre de recherche qu'après avoir confirmé que l'utilisateur **n'est pas** prof (cache `current_school_roles`, puis fallback API).

## 6. Redirection après login (`pages/login.vue`)

Le handler purge d'abord `current_school_id` et les rôles (repart d'un état propre), puis :

```
super-admin && 0 école RÉELLE  → /admin
1 école                        → set school + writeCurrentSchoolRoles + setActiveSchoolRole(premier rôle)
                                 → redirect || /
> 1 école                      → /select-school (?redirect conservé)
0 école                        → invitations en attente ?
                                   oui → /select-school (l'utilisateur doit rester connecté pour accepter)
                                   non → « Votre compte n'est associé à aucune école. » + logout
```
⚠ Pour un super-admin, le nombre d'écoles se calcule via `userService.getUserRoles(id).roles.schools` (**rôles école directs**), **jamais** via `getSchools()` — qui renvoie `School::all()` pour un super-admin et ferait croire qu'il en possède des dizaines.
⚠ Le cas **0 école + invitation en attente** ne doit **pas** déconnecter : une adhésion non acceptée n'ouvre aucun accès, mais l'acceptation se fait depuis l'application.

Erreurs gérées explicitement : 401/422 (message serveur), **429** (rate limiter à 5/min), 500, réseau.

## 7. Invitations d'école

Bandeau bleu en haut de `layouts/auth.vue`, alimenté par `invitationsService.getMine()` (`GET /api/me/invitations`).
Accepter (`accept`) / refuser (`decline`) puis `loadUserSchools()` pour rafraîchir la liste.
Tant qu'une invitation est en attente, l'école **ne voit pas le nom** de l'utilisateur (côté API) et l'accès à cette école est refusé.

## 8. Menu compte (bas de sidebar)

Point d'accès **unique** : identité + email, sélecteur d'école **et de rôle**, entrée super-admin « Administration Toollab », `Paramètres`, `Déconnexion`.
Cliquable pour **tous** (y compris mono-école). Avatar = logo/initiale de l'école, fallback initiales utilisateur.
La **topbar ne contient que le sélecteur d'année** — ne pas y remettre d'engrenage ni de `UserDropdown` (doublon supprimé).
⚠ `components/UserDropdown.vue` est **du code mort** : `layouts/admin.vue` a désormais **son propre menu compte inline** (même ergonomie qu'en vue école, commit `030e8e0`). L'ancienne documentation demandait de le conserver « pour `layouts/admin.vue` » — c'est faux, il n'est plus référencé nulle part.

## 9. Checklist

- [ ] Login via `useAuth().login()`.
- [ ] Permissions calculées sur `readActiveSchoolRoles()`, jamais sur l'union ni sur un libellé FR.
- [ ] Après modification de rôles : `writeCurrentSchoolRoles` + `SCHOOL_ROLES_UPDATED_EVENT`.
- [ ] Nouvel écran prof hors `/professeur/*` → ajouté à `teacherAllowed`.
- [ ] Accès `localStorage` sous `process.client` / `onMounted`.
- [ ] Le gating front n'est **jamais** l'unique protection : l'API doit refuser aussi.

---

**Voir aussi** : `roles-permissions` (côté serveur) · `pages-auth-publiques` (entrée dans l'app) · `nuxt-page` (middlewares)
