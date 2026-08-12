---
name: deploiement-prod
description: Chaîne de production Toollab — images Docker multi-stage poussées sur GHCR par tag git, workflows GitHub Actions, entrypoint de production (migrate, caches, supervisord, 8 workers queue), Traefik/Let's Encrypt, variables d'environnement critiques et procédure de première mise en service. À invoquer avant un déploiement, un changement d'infrastructure ou de Dockerfile.
---

# Déploiement en production

## 1. Chaîne de release

```
git tag v1.4.0 && git push origin v1.4.0
   │
   ├── toollab-api/.github/workflows/build-push.yml
   │     ghcr.io/sebauvray/toollab-api:1.4.0        (php-fpm + workers queue, target=prod)
   │     ghcr.io/sebauvray/toollab-api-nginx:1.4.0  (nginx, public/ baké)
   │
   └── toollab-front/.github/workflows/build-push.yml
         ghcr.io/sebauvray/toollab-front:1.4.0      (Nuxt SSR, target=prod)
```
Tags produits : `{{version}}` et `{{major}}.{{minor}}`. Déclencheurs : push d'un tag `v*.*.*` ou `workflow_dispatch`. Cache Buildx GHA (`scope` par image côté API).
Build-args du Dockerfile PHP : `CURRENT_USER=toollab`, `CURRENT_UID=1000`, `TZ=Europe/Paris`.

Les deux dépôts sont **distincts** (`sebauvray/toollab-api`, `sebauvray/toollab-front`) : **tagger les deux** si le changement est full-stack.

## 2. Dockerfile PHP — les 4 stages

```
base    php:8.2-fpm + tzdata + supervisor + extensions
        (pdo_mysql mbstring exif pcntl bcmath gd zip) + composer + user CURRENT_USER
dev     entrypoint.sh ; code et vendor montés au runtime
vendor  composer install --no-dev --no-scripts --optimize-autoloader (composer.json/lock seuls)
prod    supervisord.conf généré + COPY du code + vendor du stage `vendor`
        + dump-autoload --optimize --no-dev + production-entrypoint.sh
```

⚠ **Le pool php-fpm de prod tourne sous `$CURRENT_USER`**, pas `www-data` :
```dockerfile
RUN sed -ri "s/^user = www-data/user = ${CURRENT_USER}/; s/^group = www-data/group = ${CURRENT_USER}/" \
    /usr/local/etc/php-fpm.d/www.conf
```
Raison : un fichier uploadé par une requête web (import xlsx) doit être **lisible et supprimable** par le worker queue. **Ne pas annuler ce `sed`.**

Le `.dockerignore` exclut `.env`, `vendor`, `node_modules`, `.git` : l'image est immuable, le `.env` vient du serveur.

## 3. `production-entrypoint.sh` — ordre d'exécution

1. recrée l'arborescence `storage/{logs,framework/*}` + `bootstrap/cache` (storage = volume nommé, vide au premier boot), `chown`/`chmod 775` ;
2. **échoue si `APP_KEY` est vide** — elle doit venir du `.env` serveur, **jamais régénérée** (rotation = données chiffrées invalidées) ;
3. `package:discover` ;
4. **`migrate --force` avec retry** : 40 tentatives × 3 s (~120 s) — cache/queue/session sont sur la DB, donc elle doit être prête ;
5. `storage:link` ;
6. `config:cache` + `route:cache` + `view:cache` ;
7. `supervisord`.

**Une migration qui échoue bloque le déploiement.** Toujours tester `up()` **et** `down()` en local avant de tagger.

⚠ `config:cache` implique : toute modification d'une variable d'env en prod (dont `SUPER_ADMIN_EMAILS`, `CORS_ALLOWED_ORIGINS`) exige un `config:cache` ou un restart du conteneur.

## 4. Supervisord

`generate-supervisord-conf.sh` produit :
- `php-fpm` (master en root, pool en `$CURRENT_USER`) ;
- **`laravel-worker` × 8** : `php artisan queue:listen --sleep=3 --tries=3`, `stopwaitsecs=3600`, logs dans `storage/logs/worker.log` (rotation 50 Mo × 10).

`queue:listen` (et non `queue:work`) recharge le code à chaque job : pas de redémarrage nécessaire après déploiement, au prix d'un léger surcoût.

## 5. Infrastructure

- **Traefik** sur le réseau Docker externe `web`, Let's Encrypt via `certresolver=myresolver`.
- Front : `toollab.fr` + redirection `www.toollab.fr → toollab.fr` (middleware Traefik). API : `staging.api.toollab.fr`.
- **nginx** : image `fholzer/nginx-brotli:v1.26.2`. `entrypoint.sh` substitue **uniquement `${CONTAINER_NAME_API}`** dans le template, puis lance nginx. Compression Brotli niveau 6 (text, css, js, json, xml, svg, woff2, ttf).
  - `dev.conf.template` et `prod.conf.template` sont **strictement identiques** ; la seule différence entre les deux stages est que **prod bake `public/`** dans l'image (en dev, le bind mount `./:/var/www` le recouvre).
  - L'upstream FastCGI passe par une **variable + resolver Docker** (`resolver 127.0.0.11 valid=10s; set $api_upstream …; fastcgi_pass $api_upstream:9000;`) : nginx re-résout le nom en continu et **ne reste pas bloqué sur une IP morte** après recréation du conteneur API (évite le 502).
  - ⚠ **Aucun `client_max_body_size`** n'est défini → plafond d'upload réel **1 Mo**, alors que l'import annonce 10 Mo (voir `bugs-connus` A8). Aucun `php.ini` custom non plus.
- **Front Nuxt prod** : `npm ci --omit=dev && npm run build`, lancé par `node .output/server/index.mjs`. **SSR activé**.
- **Volumes** : `school_logos` monté dans `storage/app/public` (uploads persistants) ; `storage` en volume nommé (logs + uploads survivent aux déploiements).

## 6. Variables d'environnement critiques en prod

```env
APP_ENV=production
APP_DEBUG=false                 # sinon stacktraces exposées (bootstrap/app.php gate sur app.debug)
APP_KEY=base64:…                # FIGÉE, jamais régénérée
FRONTEND_URL=https://toollab.fr # base des liens d'invitation / reset dans les mails
CORS_ALLOWED_ORIGINS=https://toollab.fr,https://www.toollab.fr
SUPER_ADMIN_EMAILS=…            # CSV ; nécessite config:cache après modification
SANCTUM_EXPIRATION_MINUTES=10080
QUEUE_CONNECTION=database
MAIL_MAILER=smtp                # + host/port/credentials réels
```

## 7. Première mise en service d'une instance

```bash
# 1. .env serveur complet (APP_KEY générée UNE fois, puis figée)
# 2. démarrer les conteneurs → l'entrypoint applique les migrations
# 3. rôles
docker exec <api> php artisan db:seed --class=ProductionSeeder --force
# 4. super-admin
docker exec -it <api> php artisan toollab:create-super-admin --password=…
# 5. se connecter → /admin → créer la première école (le directeur reçoit son invitation)
```
**Ne jamais lancer `db:seed` sans `--class` en prod** (`DatabaseSeeder` injecterait le jeu de démo).

## 8. Vérifications post-déploiement

```bash
curl -s https://staging.api.toollab.fr/up
docker exec <api> php artisan migrate:status | tail -5
docker exec <api> tail -50 storage/logs/laravel.log
docker exec <api> tail -50 storage/logs/worker.log
docker exec <api> supervisorctl status
curl -sI https://staging.api.toollab.fr/api/login | grep -i strict-transport   # HSTS présent ?
```
Puis un smoke test fonctionnel : login → sélection d'école → une liste year-scopée → un export.

## 9. Rollback

Les images sont **immuables et versionnées** : redéployer le tag précédent suffit pour le code.
⚠ **Les migrations, elles, ne se rollbackent pas automatiquement.** Si la version fautive contenait une migration destructive, le rollback d'image ne suffit pas — d'où l'exigence d'un `down()` testé et d'une sauvegarde de base avant toute migration structurante.

## 10. Points d'attention connus

- `chmod -R 775` sur `storage` : correct, mais l'ancien `chmod 777` documenté ailleurs ne doit **pas** être réintroduit.
- Aucun healthcheck Docker déclaré sur les services : la disponibilité s'observe via `/up` et Traefik.
- Pas de pipeline de tests dans les workflows CI : les tests Pest doivent être lancés **manuellement** avant de tagger (voir skill `tests`).

---

**Voir aussi** : `docker-dev` · `seeders-donnees-test` (init d'instance) · `securite-api` (env de prod) · `tests`
