---
name: debug-api
description: Méthode de diagnostic de l'API Toollab — appels curl authentifiés avec les headers de contexte, lecture des logs, tinker et le piège des global scopes hors HTTP, inspection de la queue, et table de correspondance symptôme → cause pour les erreurs 400/403/404/409/500. À invoquer dès qu'un endpoint ne se comporte pas comme prévu.
---

# Débugger l'API

## 1. Appel authentifié — le template

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"relhanti@gmail.com","password":"password"}' | jq -r .token)

curl -s -H "Authorization: Bearer $TOKEN" \
     -H "X-School-Id: 1" \
     -H "X-School-Year-Id: 1" \
     -H "Accept: application/json" \
     http://localhost:8000/api/families | jq
```

**Les deux headers de contexte sont presque toujours nécessaires.** Sans `X-School-Id` : `400 Aucune école sélectionnée.` sur toute route sous le middleware `school`.

⚠ **curl est le seul outil réellement disponible.** Deux fausses pistes visibles dans le dépôt :
- `toollab-api/api/` est une collection **Bruno vide** (mai 2025) : les 12 fichiers `.bru` ont tous `url:` **blanc**, aucun body, aucun header, et `base_url: http://localhost` sans le port `:8000`. Rien n'y est exécutable — ne pas essayer de s'en servir ni de la « réparer » sans demande explicite.
- `LOG_QUERIES=true` figure dans le `.env` mais **aucun code ne lit cette variable** (vérifié sur `app/`, `config/`, `bootstrap/`). Elle ne loggue aucune requête SQL. Pour tracer les requêtes, passer par `DB::listen()` dans un tinker ou le log de MariaDB (§7).

POST :
```bash
curl -s -X POST http://localhost:8000/api/families/1/paiements/lignes \
  -H "Authorization: Bearer $TOKEN" -H "X-School-Id: 1" -H "Content-Type: application/json" \
  -d '{"type":"espece","montant":50}' | jq
```
Fichier :
```bash
curl -s -H "Authorization: Bearer $TOKEN" -H "X-School-Id: 1" \
  http://localhost:8000/api/families/export -o /tmp/export.xlsx && open /tmp/export.xlsx
```

Trouver les bons ids :
```bash
docker exec api_dev_toollab php artisan tinker --execute="
  echo \App\Models\School::first()?->id.' / '
     .\App\Models\SchoolYear::withoutGlobalScopes()->where('is_active',1)->first()?->id;
"
```

## 2. Logs

```bash
docker logs -f api_dev_toollab                              # php-fpm + entrypoint
docker exec api_dev_toollab tail -f storage/logs/laravel.log
docker exec api_dev_toollab tail -f storage/logs/laravel.log | grep -E 'SchoolContext|CheckRole|denied'
docker logs -f web_dev_toollab                              # nginx (404 de routing, tailles de body)
docker logs -f nuxt_toollab                                 # SSR Nuxt
```

Tous les refus d'accès sont **loggés** avec leur contexte (`caller_id`, ids, `path`) : `SchoolContext`, `CheckRole`, `SuperAdmin`, `SchoolYearContext`, `UserController::denyAccess`, `FamilyController::denyFamilyAccess`. Le message client est générique, **le log a la vraie raison** — c'est le premier endroit à regarder sur un 403.

### ⚠ Certaines erreurs ne laissent AUCUNE trace

Les contrôleurs ont **31 `catch` pour seulement 6 `Log::error`**. `ClassroomController` (7 catch), `CursusController` (3) et `StudentClassroomController` (3) n'en loguent **aucun**, et comme ils renvoient eux-mêmes un 500 formaté, le handler global de `bootstrap/app.php` ne les intercepte pas non plus.

Donc si une **création/modification/suppression de classe**, une **inscription** ou une **suppression de cursus** échoue : rien dans `laravel.log`. Pour diagnostiquer :
1. reproduire en **dev avec `APP_DEBUG=true`** (valeur du `.env` local) → `docker logs -f api_dev_toollab`. Le `catch` du contrôleur renvoie toujours son message générique, mais l'exception d'origine remonte souvent dans les logs php-fpm ;
2. ou ajouter temporairement un `Log::error('…', ['exception' => $e])` dans le catch concerné ;
3. ou attaquer l'endpoint en curl et regarder la requête SQL fautive via le log de MariaDB.

Les 6 endroits qui **loguent** correctement : `FamilyController::store`, `PaiementController::facture` et `::ajouterLigne`, `SchoolYearController::store`, `ProcessFamilyImportJob`, `FamilyImportService::import`.

**Corollaire du gate `app.debug`** — le handler global de `bootstrap/app.php` est **entièrement court-circuité en dev** (`if ($request->is('api/*') && !config('app.debug'))`) :

| | dev (`APP_DEBUG=true`) | staging / prod (`APP_DEBUG=false`) |
|---|---|---|
| Exception **non rattrapée** par un contrôleur | réponse Laravel brute : classe, message, **trace complète** — le diagnostic le plus rapide | `{status:error, message:'Une erreur est survenue'}` |
| `Log::error('Unhandled API exception')` | **ne s'exécute jamais** | s'exécute pour tout status ≥ 500 |

Donc : ne cherche pas `Unhandled API exception` dans `laravel.log` en local — il n'y sera pas. Inversement, un 500 générique et *silencieux* en local vient forcément d'un `catch` de contrôleur, pas du handler.

## 3. Tinker — LE piège des global scopes

```bash
docker exec -it api_dev_toollab php artisan tinker
```

Hors HTTP, `currentSchoolId()` est `null` → `BelongsToSchoolScope` fail-closed :

```php
>>> App\Models\Family::count();                       // 0  ← ce n'est PAS une base vide
>>> App\Models\Family::withoutGlobalScopes()->count(); // 60 ✓
```

Pour travailler « comme dans une requête » :
```php
>>> request()->attributes->set('current_school_id', 1);
>>> request()->attributes->set('current_school_year_id', 1);
>>> App\Models\Classroom::count();   // maintenant correct
```

Autres usages :
```php
>>> App\Models\User::where('email','x@y.fr')->first()->roles()->with('role')->get()
      ->pluck('role.slug','roleable_id');
>>> app(App\Services\TarifCalculatorService::class)
      ->calculerTotalFamille(App\Models\Family::withoutGlobalScopes()->find(1));
>>> App\Models\Role::pluck('name','slug');
```

## 4. Queue

```bash
docker exec api_dev_toollab php artisan queue:work --once
docker exec api_dev_toollab php artisan queue:failed
docker exec api_dev_toollab php artisan queue:retry all
docker exec api_dev_toollab php artisan tinker --execute="
  echo DB::table('jobs')->count().' en attente';
"
```
**En dev, aucun worker ne tourne par défaut.** Un mail ou un import qui « ne part pas » = worker absent, dans 9 cas sur 10.

## 5. Routes & config

```bash
docker exec api_dev_toollab php artisan route:list --path=api | less
docker exec api_dev_toollab php artisan route:list --path=paiements
docker exec api_dev_toollab php artisan config:clear   # après avoir modifié .env
docker exec api_dev_toollab php artisan optimize:clear
```
⚠ En dev, l'entrypoint fait un `config:cache` au démarrage : **modifier `.env` sans `config:clear` n'a aucun effet**.

## 6. Symptôme → cause

| Réponse | Cause la plus probable |
|---|---|
| `400 Aucune école sélectionnée.` | header `X-School-Id` absent / non numérique / ≤ 0 |
| `403 Vous n'avez pas accès à cette école.` | pas de `UserRole` accepté sur cette école (ni via famille/classe) |
| `403 Vous n'avez pas le rôle nécessaire…` | `checkrole:` — vérifier le **slug** et `accepted_at` non null |
| `403 Accès refusé` | gate applicatif : `callerCanAccessFamily`, `canManageUser`, `guardClassroom`, cross-tenant |
| `404 Ressource introuvable` | RMB + global scope : la ressource appartient à une **autre école ou une autre année** |
| `409 … année scolaire clôturée` | write sur une année archivée → changer `X-School-Year-Id` |
| `409 Aucune année scolaire n'est configurée` | école créée sans `SchoolYear` |
| `409 Saisie des décisions non activée` | `outcomes_open = false` sur l'année |
| `422` + `errors` | validation ; sur un paiement : dépassement du montant dû |
| `500` en prod/staging | message générique — la vraie cause est dans `laravel.log` (`Unhandled API exception`) |
| Liste vide alors que les données existent | contexte manquant (job/commande/tinker) ou mauvaise année |

## 6 bis. Fichiers & stockage

```
disque `local`  → storage/app/private     (imports : family-imports/<uuid>.xlsx)  hors web root
disque `public` → storage/app/public      (logos d'école : school_logos/)  URL = APP_URL/storage/...
```
```bash
docker exec api_dev_toollab ls -la storage/app/private/family-imports/
docker exec api_dev_toollab ls -la storage/app/public/school_logos/
docker exec api_dev_toollab php artisan storage:link      # si les logos renvoient 404
docker exec api_dev_toollab tail -f storage/logs/worker.log   # prod : logs des workers
```
Un fichier d'import qui **reste** dans `family-imports/` = le job n'a jamais tourné (le `finally` le supprime toujours).

## 7. Base de données

```bash
docker exec -it db_dev_toollab mysql -usail -ppassword toollab_api

SELECT id, label, is_active, closed_at, outcomes_open FROM school_years WHERE school_id = 1;
SELECT r.slug, ur.roleable_type, ur.roleable_id, ur.accepted_at
  FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = 1;
SELECT type_paiement, SUM(montant) FROM lignes_paiement lp
  JOIN paiements p ON p.id = lp.paiement_id WHERE p.family_id = 12 GROUP BY 1;
SHOW CREATE TABLE student_classrooms\G
```

## 8. Front ↔ API

Dans la console du navigateur :
```js
localStorage.getItem('current_school_id')
localStorage.getItem('current_school_year_id')
JSON.parse(localStorage.getItem('current_school_roles'))
localStorage.getItem('current_school_active_role')
```
Onglet Réseau : vérifier que `X-School-Id` et `X-School-Year-Id` sont bien envoyés (sinon `setupInterceptors()` n'a pas tourné, ou la page utilise `fetch` direct au lieu d'`apiClient`).

Un **401** purge le localStorage et fait un `window.location.href = '/login'` : si l'app « déconnecte toute seule », chercher quel appel a renvoyé 401 (token expiré : TTL 7 j).

## 9. Formatage

```bash
docker exec api_dev_toollab ./vendor/bin/pint          # formate
docker exec api_dev_toollab ./vendor/bin/pint --test   # vérifie sans écrire
```
Pas de config Pint personnalisée : preset Laravel par défaut. Le code existant n'est **pas** intégralement formaté — ne lance pas un `pint` global sur le projet, cela produirait un diff énorme et illisible.

---

**Voir aussi** : `multi-tenant-scoping` (scopes en tinker) · `docker-dev` · `queues-jobs-notifications` · `bugs-connus`
