---
name: pages-auth-publiques
description: Les 5 écrans publics de Toollab (login, set-password, reset-password, forgot-password, select-school) — design d'auth partagé et dupliqué dans 4 fichiers, flux de redirection après connexion, activation de compte par invitation, et anti-patterns bannis sur ces pages. À invoquer pour modifier un écran de connexion, d'activation ou de sélection d'école.
---

# Écrans publics & entrée dans l'application

## 1. Les écrans

| Route | Layout | Middleware | Rôle |
|---|---|---|---|
| `/login` | `default` | `guest` | connexion + orientation |
| `/set-password` | `default` | `guest` | **activation** d'un compte invité (token) |
| `/reset-password` | `default` | `guest` | réinitialisation (token) |
| `/forgot-password` | `default` | `guest` | demande de lien de réinitialisation |
| `/select-school` | `default` | `auth` | choix de l'école (utilisateur **connecté**) |
| `/contact` | `default` | — | page statique |

`auth.global.js` considère comme publiques : `/login`, `/contact`, `/forgot-password`, `/reset-password`, `/set-password`. `/select-school` est dans `noSchoolNeeded` (connecté, mais pas encore d'école).

## 2. Le design d'auth — DUPLIQUÉ dans 4 fichiers

`login.vue`, `set-password.vue`, `reset-password.vue` **et** `forgot-password.vue` partagent exactement la même structure et le même `<style scoped>` :

```
écran scindé :
  gauche  hidden lg:flex lg:w-1/2 bg-gray-blue border-r border-[#E6EFF5]
          .panel-mesh   (gradients radiaux primary/ambre)
          .panel-grid   (grille masquée)
          <Logo class="logo-pop"> + « Toollab » lettre par lettre (spans .letter-in, delay 350 + i×110 ms)
          + baseline .baseline-in
  droite  carte max-w-md .login-form
          bg-white rounded-2xl border border-[#E6EFF5] shadow-sm p-6 sm:p-8
          lg:bg-transparent lg:border-0 lg:shadow-none lg:rounded-none lg:p-0
          bouton bg-default w-full py-3
```
Toutes les animations sont **coupées par `@media (prefers-reduced-motion: reduce)`**.
`.login-form :deep(input)` ajuste le padding des champs (les `InputText` sont plus compacts dans l'app).

⚠ **Toute modification du design d'auth doit être répercutée dans les 4 fichiers.** Le CSS n'est pas factorisé (dette assumée). Si tu factorises, fais-le pour les 4 d'un coup, sinon ils divergent.

### Bannis sur ces pages
- **« Se souvenir de moi »** — supprimé, le champ `remember` n'est plus envoyé au login. Ne pas le réintroduire.
- **« Contactez-nous »** en pied de formulaire — supprimé.
- Messages en aplat plein (`bg-red-500`) → **tint doux** `bg-{red|green}-50 ring-1 ring-{red|green}-200`.

## 3. Flux de connexion — `pages/login.vue`

```
useAuth().login()                       ← OBLIGATOIRE (jamais authService.login() direct)
  ↓
purge current_school_id + rôles         ← repart d'un état propre
  ↓
getUserRoles(user.id) → roles.schools
  ↓
super-admin ?  écoles = ids DISTINCTS des rôles école   (JAMAIS getSchools() : renvoie School::all())
sinon       ?  écoles = getSchools()
  ↓
┌ super-admin && 0 école           → /admin
├ 1 école                          → set school + writeCurrentSchoolRoles + setActiveSchoolRole(premier)
│                                    → redirect || /
├ > 1 école                        → /select-school (?redirect conservé)
└ 0 école                          → invitations en attente ?
                                       oui → /select-school (pour pouvoir accepter)
                                       non → « Votre compte n'est associé à aucune école. » + logout
```

Le cas **0 école + invitation en attente** est essentiel : une adhésion non acceptée n'ouvre aucun accès, mais l'utilisateur doit rester connecté pour accepter depuis le bandeau in-app. **Ne pas le déconnecter.**

Erreurs traitées explicitement : 401/422 (message serveur), **429** (« Trop de tentatives… », le rate limiter est à 5/min), 500, réseau.

## 4. Activation de compte — `set-password.vue`

```
GET  ?token=…&email=…
POST /api/check-invitation-token   → { user: { email, first_name, last_name, requires_profile } }
POST /api/set-password             → { email, token, password, password_confirmation
                                       [, first_name, last_name] si requires_profile }
```
- `requires_profile` vaut `true` quand `first_name` **ou** `last_name` est vide (invitation staff sans nom) → les deux champs deviennent **obligatoires**.
- Mot de passe : min. 8, confirmé.
- Effets serveur : mot de passe posé, **acceptation de l'école du token** (`accepted_at`), **révocation de tous les tokens Sanctum**, suppression du token d'invitation.

⚠ Ces pages appellent l'API en **`fetch` direct** vers `useRuntimeConfig().public.apiUrl`, sans `apiClient` — donc **sans intercepteurs**. Anti-pattern toléré car aucun contexte (token/école) n'est requis. **Ne pas y ajouter d'appel nécessitant l'authentification.**

## 5. `/select-school`

Écran **connecté** sans contexte école (layout `default`, middleware `auth`).

Au montage, trois appels en parallèle :
```js
const [allSchools, rolesResponse, invitations] = await Promise.all([
  schoolService.getSchools(),
  userService.getUserRoles(user.id),
  invitationsService.getMine(),
])
schoolRoles.value = groupSchoolRoles(rolesResponse?.roles?.schools || [])
```

Entrer dans une école :
```js
localStorage.setItem('current_school_id', String(school.id))
const roles = schoolRoles.value[school.id] || []
writeCurrentSchoolRoles(roles)
setActiveSchoolRole(roles[0]?.slug || '')     // premier rôle par priorité
router.push(route.query.redirect || '/')
```

⚠ C'est le **deuxième endroit** (avec `layouts/auth.vue`) où les invitations en attente sont acceptées/refusées, avec un `refreshSchoolsAndRoles()` après action. Un utilisateur qui n'a **que** des invitations atterrit ici : sans ce bloc, il serait bloqué sur un écran vide. **Garder les deux implémentations synchronisées** si tu modifies le flux d'invitation.

## 6. Layout `default`

```html
<main class="bg-gray-blue min-h-screen antialiased"><slot/></main>
```
C'est tout : pas de sidebar, pas de topbar, pas de bandeau. Toute la mise en page d'un écran public est donc **dans la page elle-même**.

## 7. Checklist

- [ ] Modification de design répercutée dans les **4** fichiers d'auth.
- [ ] Flux d'invitation modifié ⇒ répercuté dans `select-school.vue` **et** `layouts/auth.vue`.
- [ ] `useAuth().login()`, jamais `authService.login()` seul.
- [ ] Pour un super-admin, écoles comptées via les **rôles école directs**.
- [ ] Cas 0 école + invitation en attente préservé (pas de déconnexion).
- [ ] Messages d'erreur en tint doux, 429 traité.
- [ ] Pas de « Se souvenir de moi » ni de « Contactez-nous ».
- [ ] Animations sous `prefers-reduced-motion`.
- [ ] Aucun appel authentifié ajouté aux pages en `fetch` direct.

---

**Voir aussi** : `front-auth-roles` · `roles-permissions` (invitations) · `design-system` · `nuxt-page`
