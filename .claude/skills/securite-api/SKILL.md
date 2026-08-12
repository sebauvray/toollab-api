---
name: securite-api
description: Modèle de sécurité de Toollab et checklist d'audit — autorisation/IDOR, mass assignment, isolation cross-tenant, messages d'erreur non divulgants, rate limiting, CORS, headers de sécurité, upload de fichiers, tokens Sanctum et XSS côté Vue. À invoquer avant de livrer du code qui touche à l'authentification, l'autorisation, un upload, ou lors d'une revue de sécurité.
---

# Sécurité API & front

## 1. Les 4 couches d'autorisation

```
1. auth:sanctum        le token existe et n'est pas expiré (TTL 7 j par défaut)
2. school              l'utilisateur a accès à l'école du header X-School-Id
                       (adhésion ACCEPTÉE, ou rôle sur une famille/classe de l'école)
3. schoolyear          l'année appartient à l'école ; écriture refusée si archivée (409)
4. checkrole:a,b       rôle (slug) accepté sur l'école courante — super-admin bypass
+ gate applicatif      appartenance de LA ressource (famille, classe, user)
```

**Les 4 premières ne suffisent jamais.** Un `director` de l'école 1 passe `checkrole:director` ; c'est le gate applicatif qui l'empêche de lire la famille 42 de l'école 2.

## 2. IDOR — le risque n°1

Tout id venant du client est suspect : path param, query, body.

```php
// ✓ modèle avec global scope → déjà filtré par le RMB
public function show(Cursus $cursus) { … }        // Cursus porte BelongsToSchool

// ✓ modèle SANS global scope → check explicite obligatoire
if ($classroom->school_id !== currentSchoolId()) {
    Log::warning('…: cross-tenant access denied', ['caller_id' => auth()->id(), …]);
    return response()->json(['message' => 'Accès refusé'], 403);
}

// ✓ famille : gate partagé
if (!FamilyController::callerCanAccessFamily($family)) { … 403 … }

// ✓ ressource imbriquée : vérifier le lien parent-enfant
if ($ligne->paiement->family_id !== $family->id) { … 404 … }
```

Modèles **sans** global scope à contrôler manuellement : `User`, `UserRole`, `UserInfo`, `ClassSchedule`, `CursusLevel`, `LignePaiement`, `Comment`, `StudentYearOutcome`, `InvitationToken`, `FamilyImport`, `School`.

Validation : `Rule::exists('table','id')` **ne vérifie pas l'appartenance**. Toujours :
```php
Rule::exists('cursus', 'id')->where('school_id', currentSchoolId())
```

## 3. Mass assignment

Restent **hors `$fillable`**, partout : `school_id`, `school_year_id`, `created_by`, `updated_by`, `main_teacher_id`.
Ils sont posés par les traits (`BelongsToSchool`, `BelongsToSchoolYear`, `TrackChangesTrait`) ou par du code explicite.

Le front **envoie encore** `school_id` dans certains payloads (`services/classe.js`, `services/cursus.js`) : c'est **inoffensif**, la valeur est ignorée. **Ne « corrige » pas ça en le remettant dans `$fillable`.**

Ne jamais écrire `Model::create($request->all())` ni `$model->update($request->all())`. Utiliser `$request->only([...])` ou `$request->validated()` **avec** un `$fillable` restreint.

`UpdateUserRequest` limite le payload à `first_name`, `last_name` (requis) et `email` (`sometimes|email|unique:users,email,{id}`). **Le mot de passe n'y figure pas** : il passe par des workflows dédiés (`/users/change-password`, reset, invitation), qui révoquent les tokens.
⚠ L'email **est** modifiable par ce chemin, sans re-vérification ni notification. Si tu durcis ce point un jour, c'est ici — et il faudra prévenir l'ancien email.

## 4. Messages d'erreur — règle de non-divulgation

Ne **jamais** exposer au client :
- le nom d'un header attendu (`X-School-Id requis` révèle l'API) ;
- la règle de validation qui a échoué sur un champ technique ;
- un nom de modèle (`Cursus not found` → énumération) ;
- `$e->getMessage()`, `getFile()`, une trace ;
- la différence entre « n'existe pas » et « pas d'accès » — **fusionner en 403 `Accès refusé`** (ou 404 générique quand on ne veut même pas confirmer l'existence).

```php
Log::warning('SchoolContext: invalid X-School-Id', [
    'user_id' => $user->id, 'raw' => $raw, 'path' => $request->path(),
]);
return response()->json(['message' => 'Requête invalide'], 400);
```

Le **code HTTP** reste informatif (il pilote l'UX du client) ; seul le **message** est générique.
`bootstrap/app.php` sanitize globalement dès que `config('app.debug')` est faux — donc **staging inclus**.

Reliquats à nettoyer quand tu passes dessus : plusieurs `catch` renvoient encore `'error' => 'Une erreur est survenue'` en plus du message (inutile) — supprime la clé plutôt que de la copier.

## 5. Authentification

- **Sanctum bearer token**, TTL `SANCTUM_EXPIRATION_MINUTES` (défaut 7 j = 10080).
- Login volontairement ambigu : `Adresse email ou mot de passe incorrect`.
- ⚠ `AuthController::login` ne vérifie **pas** la colonne `users.access` ni `schools.access` : la feature « verrouiller l'accès d'une école » **n'est pas implémentée**.
- **Révocation des tokens** : `changePassword` révoque tout **sauf le token courant** (`where('id','!=',$currentTokenId)`), `resetPassword` et `setPassword` révoquent **tous** les tokens. Toute nouvelle opération sensible sur le mot de passe **doit** révoquer.
- **Anti-énumération sur `forgot-password`** : la réponse est toujours la même, que le compte existe ou non — « *Si un compte existe pour cette adresse, un lien de réinitialisation lui a été envoyé.* ». **Ne jamais la rendre conditionnelle.**
- Mot de passe : `Password::min(8)` + `confirmed` partout (change, reset, invitation). Le token de reset expire selon `config('auth.passwords.users.expire')` (60 min par défaut) ; le token d'invitation dure **7 jours**.
- Rate limiters (`AppServiceProvider::configureRateLimiters`) : `login` 5/min par ip+email et 20/min par ip · `password-reset` 3/10 · `token-check` 20/min. Toute nouvelle route publique **doit** porter un `throttle:`.
- ⚠ Dette mineure : `PasswordResetController::sendResponse` renvoie **500** quand le reset échoue (token invalide/expiré) — ce devrait être un 422. Le front l'affiche comme une erreur serveur générique.

## 6. Invitations & confidentialité inter-écoles

`user_roles.accepted_at` : tant qu'une adhésion n'est pas acceptée, l'école **ne voit pas le nom** de l'utilisateur (`getSchoolUsers` renvoie `first_name/last_name = null` + `pending: true`) et l'accès est refusé.

En modifiant ces endpoints, **ne jamais réintroduire le nom** dans une réponse pour une adhésion `pending` : c'est une fuite de donnée personnelle entre écoles.

### ⚠ Tous les gates ne vérifient PAS `accepted_at`

| Vérifie `accepted_at` | Ne le vérifie **pas** |
|---|---|
| `SchoolContext` (adhésion **directe** uniquement) | `TeacherController::ensureTeacher()` |
| `CheckRole` | `UserController::callerHasSchoolRole()` / `canManageUser()` |
| `SchoolController::index` | `FamilyController::callerCanAccessFamily()` |
| `UserController::formatRoles()` (type `school`) | `StaffController::canManageRole()` |

Le rempart est donc `SchoolContext`… mais il accorde aussi l'accès **via un rôle famille ou classe**, **sans** exiger d'acceptation. Scénario théorique : un utilisateur invité comme professeur (invitation **non acceptée**) qui possède par ailleurs un rôle `student`/`responsible` sur une famille de la même école franchit `SchoolContext` par ce second chemin, puis `ensureTeacher()` lui accorde les droits professeur liés à l'invitation qu'il n'a jamais acceptée.

Improbable mais réel. **Si tu ajoutes un gate basé sur un `UserRole` de type `school`, ajoute `->whereNotNull('accepted_at')`.**

## 7. Upload de fichiers

| Contexte | Règle |
|---|---|
| Logo d'école | `image\|mimes:jpeg,png,jpg,gif,webp\|max:2048` — **SVG INTERDIT** (XSS via SVG). Réintroduire le SVG exigerait `enshrined/svg-sanitize`. |
| Import familles | `file\|max:10240` + extension ∈ `xlsx`, `csv`, stockée sur le disque `local` (hors web root) sous un nom UUID, **supprimée après traitement** |

Le front doit refléter la même whitelist (`accept="image/jpeg,image/jpg,image/png,image/gif"` dans `/settings`).

## 8. CORS & headers

```php
// config/cors.php
'allowed_origins' => env('CORS_ALLOWED_ORIGINS')       // liste blanche, JAMAIS '*'
'allowed_headers' => ['Accept','Authorization','Content-Type','X-Requested-With',
                      'X-School-Id','X-School-Year-Id']
'supports_credentials' => false, 'max_age' => 3600
```
Tout nouveau header custom doit être ajouté à `allowed_headers`, sinon le préflight échoue.

`SecurityHeaders` (middleware global) : `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cross-Origin-Resource-Policy: same-site`, + HSTS 2 ans si HTTPS.

## 9. Injection

- **SQL** : Eloquent ou bindings. `whereRaw('… ?', [$v])`, jamais de concaténation dans `DB::raw()`. Les `orderByRaw("FIELD(day, 'Lundi', …)")` existants sont des littéraux constants — acceptable.
- **Formule Excel** : `ExportService` écrit tout le texte en `inlineStr`, jamais `<f>` (voir skill `exports-xlsx`).
- **XSS Vue** : `{{ }}` échappe. `v-html` **uniquement** sur du contenu constant du code — l'unique usage légitime est la map d'icônes SVG `typesIcons` de `paiement.vue`. **Jamais** de `v-html` sur une donnée utilisateur.

## 9 bis. Secrets et données réelles dans le dépôt

- **`database/seeders/AlQalamSeeder.php`** contient les **vraies adresses e-mail** de trois membres d'une école cliente, avec le mot de passe **`password`** en dur et `accepted_at` pré-rempli. Ne pas l'exécuter sur une instance accessible sans changer les mots de passe immédiatement après.
- **`resources/data/EXP_ELEVE.csv`** (~84 Ko) est un **export d'élèves réels** versionné dans le dépôt (noms, dates de naissance, adresses, téléphones des responsables). C'est une donnée personnelle au sens RGPD, présente dans l'historique git.
- `ToollabSeeder` crée le directeur `relhanti@gmail.com` / `password` — acceptable en dev, **jamais** en prod (utiliser `ProductionSeeder` + `toollab:create-super-admin`).

## 10. Super-admin

`is_super_admin` est **dérivé de l'email** via l'env `SUPER_ADMIN_EMAILS`. Conséquences sécurité :
- quiconque peut modifier le `.env` de prod devient super-admin ;
- en prod la config est **cachée** : changer l'env sans `config:cache`/restart n'a aucun effet (dans les deux sens) ;
- le super-admin **bypasse `CheckRole`** et la plupart des gates — l'utiliser avec parcimonie dans les tests manuels.

## 11. Checklist d'audit d'une modification

- [ ] Chaque id du payload est validé **avec** contrainte d'appartenance école.
- [ ] Chaque modèle sans global scope est vérifié explicitement (`school_id`, lien parent).
- [ ] Aucun champ technique ajouté à `$fillable`.
- [ ] Route mutative sous `auth:sanctum` + `school` (+ `schoolyear`, + `checkrole`).
- [ ] Messages clients génériques ; détails en `Log::warning`/`Log::error`.
- [ ] Nouvelle route publique → `throttle:`.
- [ ] Opération sensible sur mot de passe → révocation des tokens.
- [ ] Upload → mimes whitelistés, SVG exclu, stockage hors web root si non public.
- [ ] Nouveau header → `config/cors.php`.
- [ ] Pas de `v-html` sur du contenu utilisateur.
- [ ] Réponses `pending` : nom masqué.

---

**Voir aussi** : `multi-tenant-scoping` · `roles-permissions` · `api-endpoint` · `workflow-livraison`
