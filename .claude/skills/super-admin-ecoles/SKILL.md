---
name: super-admin-ecoles
description: Espace super-admin de Toollab — mécanique is_super_admin dérivée de l'email, middleware superadmin, création et gestion des écoles, layout /admin, redirection de login, et bootstrap d'une instance vierge. À invoquer pour modifier l'onboarding d'une école, l'espace plateforme, ou débugger un accès super-admin.
---

# Super-admin & gestion des écoles

## 1. `is_super_admin` — dérivé, jamais stocké

```php
// App\Models\User
public function getIsSuperAdminAttribute(): bool
{
    return in_array($this->email, config('toollab.super_admin_emails', []), true);
}
protected $appends = ['is_super_admin'];        // → présent dans la réponse /login
```
`config/toollab.php` parse l'env **`SUPER_ADMIN_EMAILS`** (CSV, trim, valeurs vides filtrées).

Conséquences :
- **aucun « grant » en base** : ajouter un email à l'env suffit ;
- **en prod la config est cachée** (`config:cache` dans l'entrypoint) → un changement d'env est sans effet avant `php artisan config:cache` ou un restart. En dev, l'entrypoint fait aussi un `config:cache` → `php artisan config:clear` après modification ;
- un super-admin **bypasse** `CheckRole`, `SchoolContext` (accès à toute école), `canManageUser`, `callerCanAccessFamily`.

## 2. Middleware `superadmin`

```php
'superadmin' => App\Http\Middleware\SuperAdmin::class
```
401 si non authentifié, **403 `Accès refusé`** + `Log::warning` sinon. Monté avant `SchoolContext` dans le bloc `priority`.

Une seule route l'utilise : `POST /api/schools`.
Les autres protections super-admin sont **applicatives** : `UserController::store/getAllUsersWithRoles` (403 si non super-admin), `SchoolController::destroy`, `UserController::destroy` (refuse de supprimer un super-admin).

## 3. Création d'une école — `POST /api/schools`

`StoreSchoolRequest` : `name`, `address`, `access` (**required boolean**), `director_first_name`, `director_last_name`, `director_email` requis ; `email`, `phone`, `zipcode`, `city`, `country`, `logo` (image, **pas de SVG**, ≤ 2 Mo), `siret`, `vat_mode`, `vat_number` optionnels.

Transaction :
1. logo stocké sur le disque `public` (`school_logos/`) ;
2. `School` créée ;
3. directeur : `User` existant réutilisé par email, sinon créé (mot de passe aléatoire) ;
4. `UserRole(director, school)` — **accepté immédiatement** si le directeur existait déjà (le super-admin fournit lui-même son identité) ;
5. **`SchoolYear` active** `AAAA-AAAA+1` (bascule au 1er septembre) — indispensable, sinon 409 partout ;
6. si directeur nouveau : `InvitationToken` (7 j, avec `school_id`) + mail `DirectorInvitation`.

En cas d'échec : rollback **et suppression du logo** déjà stocké.

⚠ Deux pièges :
- le `catch` renvoie un 500 générique **sans logger l'exception** → pour débugger, logger `$e` temporairement ;
- `siret`, `vat_mode`, `vat_number` sont validés mais **`store()` les persiste bien** ; en revanche l'écran de création ne les propose pas toujours — ils se règlent ensuite dans `/settings`.

⚠ Sans `RoleSeeder`, cette route plante en 500 (`Role::where('slug','director')` → null). C'est l'erreur n°1 sur une instance vierge.

## 4. Écrans `/admin` (layout `admin`, middleware `super-admin`)

```
/admin                      tableau de bord : compteur d'écoles + raccourcis
/admin/schools              liste des écoles
/admin/schools/new          création (formulaire école + directeur)
/admin/schools/[id]         détail : identité, logo, SIRET, régime TVA (libellés lisibles
                            via vatModeLabels), et le DIRECTEUR (school.director, exposé par
                            SchoolController::show). Champs vides → « Non renseigné »
/admin/schools/[id]/edit    édition
```
`layouts/admin.vue` : navigation réduite à **deux entrées** (`/admin`, `/admin/schools`) + un **menu compte inline** (initiales, écoles, déconnexion) reprenant l'ergonomie de la vue école (commit `030e8e0`).
⚠ Ce menu est **écrit directement dans le layout** ; `components/UserDropdown.vue` n'est **plus utilisé nulle part** (contrairement à ce qu'affirmait l'ancienne documentation).

Comportements du layout à connaître :
- **Au montage, il purge systématiquement `current_school_id` et les rôles** (`localStorage.removeItem` + `clearCurrentSchoolRoles()`). Entrer dans `/admin` par n'importe quel chemin — lien, URL directe, rechargement — fait donc **sortir du contexte école**. C'est voulu : le mode plateforme est global.
- `switchToSchool(school)` repose `current_school_id` puis `router.push('/')` **sans écrire les rôles** — c'est `layouts/auth.vue::loadUserSchools()` qui les recharge à l'arrivée. Entre les deux, `current_school_roles` est absent, ce qui désactive le confinement professeur d'`auth.global.js` (`hasRoleCache` false) : pas de redirection intempestive.
- Bandeau violet « mode plateforme » en tête de sidebar, largeur fixe `w-64` (pas de repli comme la sidebar applicative).

⚠ L'espace `/admin` est **hors design system** : `rounded-lg`, `border` neutre, `text-lg`/`text-xl`, pas de `PageContainer` ni de `BreadCrumb`. C'est un back-office interne assumé — l'aligner n'est pas prioritaire, mais ne le prends pas comme référence de style.
Aucune route `/admin/*` n'est year-scopée : la plateforme ne dépend pas d'une année scolaire.

Entrer en mode plateforme depuis l'app : menu compte → « Administration Toollab » → `goToAdmin()` qui **retire `current_school_id` et purge les rôles** avant de router vers `/admin`.

### `pages/admin/schools/new.vue` — ce que le formulaire envoie

```js
{ name, email, phone, address, zipcode, city, country: 'France', access: true,
  director_first_name, director_last_name, director_email }
```
- **`siret`, `vat_mode`, `vat_number` et le logo n'y figurent pas** : ils se renseignent ensuite dans `/settings` → onglet « Mon établissement ». (L'API les accepte pourtant à la création.)
- `country` vaut « France » par défaut, `access` vaut `true` (converti en `'1'` par le service, cf. §4).
- Le flash de succès distingue les deux cas grâce à `school.invitation_sent` renvoyé par l'API :
  *« Un email d'invitation a été envoyé au directeur »* vs *« Le directeur existant a été rattaché à cette école »*.
- Les 422 remplissent `errors.value = e.response.data.errors`.

⚠ Cette page affiche un **bandeau listant TOUTES les erreurs** de validation : sans lui, une erreur sur un champ non rendu (`access`, `email`…) resterait invisible derrière « Veuillez corriger les erreurs ». Conserver ce bandeau.

⚠ **Booléen en FormData** : `services/school.js` envoie en multipart → convertir `true`/`false` en `'1'`/`'0'`, sinon la règle Laravel `boolean` rejette la chaîne `"true"`.
⚠ `updateSchool` fait un **POST avec `_method=PUT`** (PHP ne parse pas le multipart en PUT).

## 5. Redirection après login (`pages/login.vue`)

```
super-admin && 0 école RÉELLE   → /admin
1 école                          → auto-sélection + /
> 1 école                        → /select-school
```
⚠ Pour un super-admin, le nombre d'écoles se calcule via `userService.getUserRoles(id).roles.schools` (**rôles école directs**), **jamais** via `getSchools()` — qui renvoie `School::all()` pour un super-admin.

## 6. Bootstrap d'une instance vierge

Œuf/poule : tout exige un super-admin connecté, donc rien n'est créable in-app.

```bash
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force        # rôles
php artisan toollab:create-super-admin --password=…         # compte plateforme
# puis login → /admin → créer la première école
```
La commande est **idempotente** (crée ou réinitialise le mot de passe). Sans argument, elle traite **tous** les emails de `SUPER_ADMIN_EMAILS`.

Alternatives dev : `migrate:fresh --seed` (crée `relhanti@gmail.com`/`password` mais comme **directeur avec école** → atterrit sur `/`, pas `/admin`), ou `tinker`.

## 7. Limites connues

- **`schools.access` n'est vérifié nulle part** : « verrouiller l'accès d'une école » n'est pas implémenté (création = toujours `access=true`).
- `SchoolController::destroy` existe mais **n'est routé nulle part** → `DELETE /api/schools/{id}` renvoie 404 (et `services/school.js::deleteSchool` pointe dans le vide).
- `GET /api/schools/{school}/families` est routé mais **son corps est vide**.
- `SchoolController::index` renvoie **`School::all()`** pour un super-admin (pas de pagination) — acceptable au volume actuel.

## 8. Checklist

- [ ] Nouvelle route plateforme → middleware `superadmin` (pas seulement un check applicatif).
- [ ] Toute création d'école crée **aussi** une `SchoolYear` active.
- [ ] Rôles seedés avant toute création d'école.
- [ ] Booléens convertis en `'1'`/`'0'` en multipart.
- [ ] Menu compte de `layouts/admin.vue` conservé (il est inline, pas dans un composant).
- [ ] Modification de `SUPER_ADMIN_EMAILS` suivie d'un `config:clear` (dev) / `config:cache` (prod).

---

**Voir aussi** : `roles-permissions` · `seeders-donnees-test` · `securite-api` · `facture-pdf` (champs TVA)
