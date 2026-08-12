---
name: api-endpoint
description: Créer ou modifier un endpoint de l'API Laravel Toollab — placement dans routes/api.php selon les groupes de middleware (auth/school/schoolyear/checkrole), format de réponse {status,message,data}, validation, gates d'autorisation, pagination, gestion d'erreurs et logs. À invoquer avant d'ajouter ou de toucher une route API.
---

# Créer / modifier un endpoint API

## 1. Où placer la route — `routes/api.php`

Le fichier est une **cascade de groupes de middleware**. Le placement définit à lui seul la sécurité de l'endpoint. Choisis le bon niveau :

```
(racine, public)                       login, forgot/reset-password, check-*-token, set-password
                                       → toujours avec throttle:login|password-reset|token-check

auth:sanctum
├── (sans contexte école)              logout, /me/invitations*, users/change-password,
│                                      users/{user}/roles, users/{user}, schools (index/show/store)
│
└── middleware 'school'                → header X-School-Id requis
    ├── school-years/*                 volontairement NON year-scopé
    │                                  (sinon impossible de lister les années archivées)
    ├── classrooms/{id}/reconduct      hors year-scope (on reconduit depuis une année archivée)
    ├── users/* (lecture, staff)       gestion plateforme, autorisée même sur année archivée
    │
    └── middleware 'schoolyear'        → header X-School-Year-Id optionnel (défaut = année active)
        │                                mutations bloquées en 409 si année archivée
        ├── users (store/update/delete/info)
        ├── families/*                 (+ export/import sous checkrole:director,admin)
        ├── cursus/*                   checkrole:director,admin
        ├── classrooms/*               lecture libre, write checkrole:director,admin
        ├── admin/classrooms/*         checkrole:director,admin
        ├── admin/outcomes             checkrole:director,admin
        ├── schedules                  checkrole:director,admin
        ├── teacher/*                  (double 'schoolyear', inoffensif) guardClassroom interne
        ├── student-classrooms/*       checkrole:director,admin,registar
        ├── tarification/*             checkrole:director,admin
        ├── families/{family}/paiements  lecture ouverte (gate famille), write checkrole:…,registar
        └── statistics/*               checkrole:admin,director
```

**Règle** : toute route mutative (POST/PUT/PATCH/DELETE) qui touche des données pédagogiques ou financières va sous `schoolyear`. Une route de gestion de compte/plateforme reste sous `school` seul.

⚠ **Collisions de routes** — deux mécanismes coexistent, ne les confonds pas :

1. **`->whereNumber('user')`** sur toutes les routes `users/{user}`. C'est indispensable ici : `GET /users/{user}` est déclaré **avant** le groupe `users` qui contient `/search`, `/teachers`, `/by-context`. Sans la contrainte numérique, `/api/users/teachers` serait capturé par `{user}` et partirait en 404.
2. **L'ordre déclaratif** pour les segments littéraux d'un même groupe : `families/export`, `families/import`, `families/imports/{id}` sont déclarés **avant** `families/{family}`.

Toute nouvelle route littérale sous un préfixe qui possède déjà un `{param}` doit soit être déclarée avant, soit s'appuyer sur une contrainte (`whereNumber`).

## 2. Squelette d'un contrôleur

```php
public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'cursus_id' => ['required', Rule::exists('cursus', 'id')->where('school_id', currentSchoolId())],
    ]);

    try {
        $model = DB::transaction(function () use ($request) {
            $model = Machin::create($request->only(['name', 'cursus_id']));
            // … opérations multi-tables ici
            return $model;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Machin créé avec succès',
            'data' => $model,
        ], 201);
    } catch (\Exception $e) {
        Log::error('Machin.store failed', ['exception' => $e]);
        return response()->json([
            'status' => 'error',
            'message' => 'Une erreur est survenue',
        ], 500);
    }
}
```

Points non négociables :
- **`{status, message, data}`** pour tout nouvel endpoint. (Les retours bruts existants — `AuthController@login`, `SchoolController@index/show/update`, `UserController` CRUD — sont du legacy, ne pas étendre.)
- **Jamais** `'error' => $e->getMessage()` dans la réponse. Le pattern `['error' => 'Une erreur est survenue']` qu'on trouve encore dans certains catch est inutile : supprime-le plutôt que de le copier.
- ⚠ **TOUJOURS logger dans le `catch`.** Un catch qui renvoie lui-même un 500 formaté **court-circuite le handler global** de `bootstrap/app.php` : sans `Log::error`, l'erreur ne laisse **aucune trace**. C'est déjà le cas de 13 blocs (`ClassroomController`, `CursusController`, `StudentClassroomController` — voir `bugs-connus` C4 ter). Ne pas en ajouter un quatorzième.
- `DB::transaction` (ou `beginTransaction`/`commit`/`rollBack`) dès qu'on écrit dans ≥ 2 tables.
- `Log::warning` pour un refus d'accès, `Log::error` pour une exception — avec `caller_id`, ids concernés, `path`.

## 3. Validation

### ⚠ Il n'y a AUCUN fichier de langue française

`APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, et **le dossier `lang/` n'existe pas**. Les messages de validation par défaut de Laravel sortent donc **en anglais** (« The name field is required. ») et remontent tels quels jusqu'à l'interface française.

D'où les longs tableaux `messages()` écrits à la main dans 7 des 9 FormRequests. **Toute nouvelle règle de validation exposée à l'utilisateur doit avoir son message FR**, soit :
```php
$request->validate($rules, [
    'name.required' => 'Le nom est requis.',
    'size.min'      => 'La capacité doit être d\'au moins 1.',
]);
```
soit via `messages()` dans un FormRequest.
(`StoreUserRequest` et `UpdateUserRequest` n'en ont pas — ils ne sont exposés qu'au super-admin.)

Alternative durable si le volume augmente : publier `lang/fr/validation.php` et passer `APP_LOCALE=fr`. Ce n'est **pas** fait aujourd'hui : ne pas supposer que les messages génériques sont traduits.

### Deux styles de validation, les deux acceptés
- **Inline** `$request->validate([...])` — style des contrôleurs récents, préféré pour les endpoints simples.
- **FormRequest** (`app/Http/Requests/`) — 9 fichiers, utilisés pour classroom/cursus/school/user/staff. `authorize()` retourne `true` partout **sauf** `StaffRequest`. Les messages FR sont exhaustifs dans ces classes.

Règles de validation spécifiques Toollab :
```php
// appartenance école dans la règle elle-même
Rule::exists('cursus', 'id')->where('school_id', $schoolId)

// un teacher_id doit être prof DE CETTE école
Rule::exists('user_roles', 'user_id')->where(fn($q) => $q
    ->where('roleable_type', 'school')->where('roleable_id', $schoolId)
    ->whereIn('role_id', Role::query()->where('slug', 'teacher')->pluck('id')))

// horaires
'schedules.*.start_time' => 'required|date_format:H:i',
'schedules.*.end_time'   => 'required|date_format:H:i|after:schedules.*.start_time',

// sémantique « état complet » : present|array, JAMAIS required|min:1
'records'   => 'present|array',
'decisions' => 'present|array',
```

`present|array` est un choix **délibéré** : il autorise un tableau vide, ce qui permet de tout effacer (voir skill `emargement-decisions`).

## 4. Autorisation

Ne réinvente pas de gate — réutilise (détail dans la skill `roles-permissions`) :

```php
FamilyController::callerCanAccessFamily($family)   // public static, réutilisable partout
$this->canManageUser($user) / canTouchUserInCurrentSchool($user) / callerHasSchoolRole($id, $slugs)
$this->guardClassroom($classroom)                  // TeacherController
```

Et le check d'appartenance minimal quand tu reçois un modèle sans global scope :
```php
if ($classroom->school_id !== currentSchoolId()) {
    return response()->json(['message' => 'Accès refusé'], 403);
}
```

## 5. Pagination

Deux implémentations coexistantes :

```php
// (a) trait — FamilyController
use App\Traits\PaginationTrait;
$data = $this->paginateQuery($query, $request);   // défaut 10, cap 100
// → ['items' => …, 'pagination' => ['current_page','per_page','total','total_pages']]

// (b) manuelle — ClassroomController, CursusController, StatisticsController
$perPage = min((int) $request->get('per_page', 10), 100);
$paginator = $query->paginate($perPage);
```

**Standard : défaut 10, plafond 100, partout.** Si tu ajoutes un paginateur manuel, garde exactement ce contrat de sortie (le front `DataTable` en dépend).

⚠ **Les 5 sites existants oublient la borne basse** — `min(…, 100)` sans `max(1, …)`. `?per_page=0` (ou toute valeur non numérique, qui se caste en `0`) donne `ceil($total / 0)` → **`DivisionByZeroError` → 500**, dans `paginateQuery` comme dans les paginateurs manuels et dans `LengthAwarePaginator` lui-même. Non atteignable depuis l'UI (`useTablePerPage` verrouille 10/25/50/100), mais trivial à déclencher à la main.

Dans **tout** nouveau code, borne des deux côtés — et corrige au passage si tu touches un de ces contrôleurs :
```php
$perPage = max(1, min((int) $request->input('per_page', 10), 100));
$page    = max(1, (int) $request->input('page', 1));   // ⚠ caster AUSSI page
```
`page` non casté est le même piège : `('' - 1) * $perPage` lève une `TypeError` en PHP 8 (cf. `bugs-connus` A14).

**Règle d'or** : une ligne paginée = une entité paginée. Ne jamais émettre N lignes par item via `flatMap` (bug historique de `FamilyController::index`, corrigé en `->map()` avec responsables joints par `, `).

Listes volontairement **non paginées** : `/api/admin/classrooms`, `/api/admin/outcomes`, `/api/schools`, `/api/users/*`, `/api/schedules`, `/api/statistics/search-payments`, détail famille.

## 6. Réponses de fichier (export, facture)

```php
return ExportService::xlsx('nom_fichier', $headers, $rows);      // skill exports-xlsx
return FacturePdfService::download('facture_'.$numero, $data);   // skill facture-pdf
```
Les deux renvoient un `BinaryFileResponse` avec `deleteFileAfterSend(true)` + `X-Content-Type-Options: nosniff`. Côté front, le service doit passer `responseType: 'blob'`.

## 7. Erreurs globales

`bootstrap/app.php` intercepte, **uniquement quand `config('app.debug')` est faux** (donc staging inclus) :
- `NotFoundHttpException` sur `api/*` → `{status:'error', message:'Ressource introuvable'}` 404 (toujours, même en debug)
- `ValidationException` → 422 + `errors`
- `AuthenticationException` → 401 `Non authentifié`
- `AccessDeniedHttpException` → 403 `Accès refusé`
- tout le reste ≥ 500 → log + `Une erreur est survenue`

Donc **en dev (`APP_DEBUG=true`) tu vois les vraies traces**, en staging/prod non. Ne « corrige » pas un message qui te semble trop verbeux en local : c'est le comportement attendu.

## 8. Checklist avant de valider un endpoint

- [ ] Placé dans le bon groupe de middleware (§1) — mutatif ⇒ `schoolyear`.
- [ ] `checkrole:` si réservé ; le registar doit-il passer ?
- [ ] Gate d'appartenance ressource (école / famille / classe).
- [ ] Ids du payload validés avec contrainte `school_id`.
- [ ] `{status, message, data}` + codes HTTP corrects.
- [ ] Aucun détail technique dans le message client ; `Log::` côté serveur.
- [ ] Transaction si multi-tables.
- [ ] Eager loading pour éviter le N+1 (`with`, `withCount`).
- [ ] Route déclarée **avant** les routes paramétrées si littérale.
- [ ] Le service front correspondant existe (`toollab-front/services/*.js`).

---

**Voir aussi** : `multi-tenant-scoping` (contexte et scopes) · `securite-api` (gates et messages) · `roles-permissions` (qui a le droit) · `front-services-api` (côté consommateur)
