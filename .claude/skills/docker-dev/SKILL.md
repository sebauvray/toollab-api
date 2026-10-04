---
name: docker-dev
description: Environnement de développement Docker de Toollab — démarrage des deux stacks (API et front), configuration .env, réseau externe dev_toollab, commandes artisan/npm quotidiennes, reset de base, logs et résolution des problèmes courants (permissions, vendor, port occupé). À invoquer pour lancer, redémarrer, reset ou débugger l'environnement local.
---

# Environnement de développement Docker

## 1. Premier démarrage

```bash
docker network create dev_toollab          # réseau EXTERNE, une seule fois

cd toollab-api
cp .env.example .env
#   → aligner CURRENT_USER / CURRENT_UID / CURRENT_GID avec l'hôte :
#     whoami · id -u · id -g            (sinon volumes bind en root → permissions cassées)
#   → CONTAINER_PORT_WEB=8000 et CONTAINER_PORT_DB=3306 (le .env.example dit 8080/3307)
docker compose up -d --build

cd ../toollab-front
cp .env.example .env                        # NUXT_PUBLIC_API_URL=http://localhost:8000
docker compose up -d --build

docker exec api_dev_toollab php artisan migrate --seed --force
```

| Service | URL | Conteneur |
|---|---|---|
| Front Nuxt | http://localhost:3000 | `nuxt_toollab` |
| API Laravel | http://localhost:8000 | `web_dev_toollab` (nginx) → `api_dev_toollab` (php-fpm) |
| MariaDB | localhost:3306 · `sail`/`password` · root `passwordroot` | `db_dev_toollab` |
| Maildev UI / SMTP | http://localhost:1080 · :1025 | `maildev` (profil `dev`) |
| Health check | http://localhost:8000/up | |

## 2. Ce que fait l'entrypoint dev automatiquement

`docker/php/entrypoint.sh`, à **chaque** démarrage du conteneur :
1. exporte les variables du `.env` dans le shell ;
2. `composer install` (incrémental grâce au volume nommé `vendor`) ;
3. génère `APP_KEY` **si elle est vide** ;
4. attend MariaDB (5 s max) puis, **si `information_schema` compte 0 table**, lance `migrate --force` ;
5. `config:clear` + **`config:cache`** ;
6. `exec php-fpm`.

⚠ Conséquence du point 5 : **modifier `.env` sans relancer `config:clear` (ou le conteneur) n'a aucun effet.**
```bash
docker exec api_dev_toollab php artisan config:clear
```

## 3. Volumes nommés — pourquoi

```yaml
api:   volumes: [ ./:/var/www , vendor:/var/www/vendor ]
nuxt:  volumes: [ .:/app     , node_modules:/app/node_modules ]
```
Le bind mount du code masquerait `vendor/` et `node_modules/` de l'image. Les volumes nommés les préservent **et** rendent `composer install` / `npm install` incrémentaux.
Le stage `dev` du Dockerfile pré-crée `/var/www/vendor` avec le bon propriétaire : au premier montage, le volume hérite de cet ownership (sinon root → `composer install` échoue).

## 4. Commandes quotidiennes

```bash
# logs
docker logs -f api_dev_toollab          # php-fpm + entrypoint
docker logs -f web_dev_toollab          # nginx
docker logs -f nuxt_toollab             # SSR Nuxt
docker exec api_dev_toollab tail -f storage/logs/laravel.log

# artisan
docker exec api_dev_toollab php artisan migrate --force
docker exec api_dev_toollab php artisan migrate:status
docker exec api_dev_toollab php artisan route:list --path=api
docker exec -it api_dev_toollab php artisan tinker
docker exec api_dev_toollab php artisan queue:work --once
docker exec api_dev_toollab ./vendor/bin/pest
docker exec api_dev_toollab ./vendor/bin/pint --test

# base
docker exec -it db_dev_toollab mysql -usail -ppassword toollab_api
docker exec api_dev_toollab php artisan migrate:fresh --seed --force   # RESET TOTAL

# front
docker exec nuxt_toollab npm install <pkg>
docker exec nuxt_toollab npm run test:roles
docker restart nuxt_toollab

# cycle de vie
docker compose restart api
docker compose down && docker compose up -d --build
docker compose down -v          # ⚠ SUPPRIME LE VOLUME DB (perte totale des données)
```

## 5. Reset de base

```bash
# reset avec données de démo (École Al-Hikma, relhanti@gmail.com / password)
docker exec api_dev_toollab php artisan migrate:fresh --seed --force

# reset propre, sans fausse donnée
docker exec api_dev_toollab php artisan migrate:fresh --force
docker exec api_dev_toollab php artisan db:seed --class=ProductionSeeder --force
docker exec -it api_dev_toollab php artisan toollab:create-super-admin --password=password
```
Après un reset, **vider le localStorage du navigateur** (`current_school_id` pointe sur une école disparue) ou se déconnecter/reconnecter.

## 6. Problèmes courants

| Symptôme | Cause / correction |
|---|---|
| `network dev_toollab not found` | `docker network create dev_toollab` |
| Permission denied sur `storage/` ou `vendor/` | `CURRENT_UID`/`CURRENT_GID` du `.env` ≠ hôte → corriger puis `docker compose up -d --build` |
| Port 8000/3000/3306 déjà utilisé | changer `CONTAINER_PORT_WEB`/`CONTAINER_PORT_DB` (+ `NUXT_PUBLIC_API_URL` du front), ou arrêter le service concurrent. Maildev a ses ports **en dur** (1080/1025) dans `docker-compose.yml` : sur une machine où ils sont pris, créer un `docker-compose.override.yml` local (`ports: !override [...]`) exclu via `.git/info/exclude` — ne jamais modifier le compose versionné pour une contrainte locale. Vérifier d'abord `docker ps` et `lsof -iTCP -sTCP:LISTEN` : d'autres projets Docker tournent sur cette machine |
| Modification de `.env` toujours sans effet après `config:clear` | le `.env` est injecté en **variables d'environnement du conteneur** (`env_file`) à sa création : `docker compose up -d api` pour le recréer (puis relancer le worker de queue, qui meurt avec le conteneur) |
| E-mails envoyés avec l'expéditeur « `${APP_NAME}` » après un redémarrage de l'API | bug de l'entrypoint de dev (voir `bugs-connus` A-entrypoint-dev) → `docker exec api_dev_toollab php artisan config:clear` |
| Modification de `docker/php/entrypoint.sh` sans effet | il est **copié dans l'image** au build (`COPY … /usr/local/bin/`) → `docker compose up -d --build api` |
| Logo cassé dans les e-mails en dev | `APP_URL` doit inclure le port de l'API (`http://localhost:8010` ici) : le logo est servi par l'API, pas par le front |
| Le worker `queue:listen` s'arrête tout seul (`exceeded the timeout of 60 sec` dans les logs) | un envoi SMTP vers Maildev a bloqué plus de 60 s et `queue:listen` meurt avec son enfant → relancer ; préférer `queue:work --tries=3 --timeout=90`, qui tue le job sans s'arrêter |
| Job mail relâché avec `421 Timeout - closing connection` | `queue:work` longue durée garde la connexion SMTP ouverte et Maildev la coupe ; le job repart après le backoff (60 s). En dev, préférer `queue:listen` |
| L'API répond 500 au premier démarrage | migrations pas encore passées → `migrate --force` |
| Modification de `.env` sans effet | `config:clear` (l'entrypoint met la config en cache) |
| `vendor` vide / classe introuvable | `docker exec api_dev_toollab composer install` |
| Mail/import bloqué | aucun worker : `docker exec -d api_dev_toollab php artisan queue:listen` |
| Maildev absent | profil dev désactivé → `COMPOSE_PROFILES=dev` dans le `.env` |
| Hot reload Nuxt inactif | volume `.:/app` non monté ou conteneur à redémarrer |
| Logo d'école 404 | `php artisan storage:link` (symlink `public/storage`) |
| **413** sur un upload | aucun `client_max_body_size` dans nginx → plafond réel **1 Mo** (voir `bugs-connus` A8) |
| **502** après `docker compose up -d api` | normalement évité : nginx re-résout l'upstream via le DNS Docker (`resolver 127.0.0.11 valid=10s`). S'il persiste, redémarrer nginx |

## 7. Points de configuration à connaître

- `DB_HOST` doit valoir **le nom du conteneur DB** (`db_dev_toollab`), pas `localhost`.
- `NETWORK_NAME=dev_toollab` : le réseau est **externe**, partagé entre les deux stacks.
- Le front expose `NUXT_PUBLIC_API_URL`, **lu au runtime** (pas figé au build) → le même bundle est déployable partout.
- `CORS_ALLOWED_ORIGINS` doit lister `http://localhost:3000`, sinon toutes les requêtes navigateur échouent au préflight.
- `SUPER_ADMIN_EMAILS` (CSV) pilote `is_super_admin` — en dev c'est lu en direct (pas de cache de config… **sauf** que l'entrypoint fait un `config:cache` : après modification, `config:clear`).

---

**Voir aussi** : `debug-api` · `seeders-donnees-test` · `deploiement-prod` · `bugs-connus` (limite d'upload)
