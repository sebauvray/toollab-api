#!/bin/sh
set -e

cd /var/www

# storage est un volume nommé (persiste logs/uploads entre déploiements) : on
# (re)crée l'arborescence attendue par Laravel, vide au premier démarrage.
sudo mkdir -p \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache
sudo chown -R $CURRENT_USER:$CURRENT_USER storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

sudo mkdir -p /var/log
sudo touch /var/log/supervisord.log
sudo chmod 644 /etc/supervisord.conf
sudo chown -R $CURRENT_USER:$CURRENT_USER /var/log
sudo chmod -R 755 /var/log

# APP_KEY doit être fourni via le .env du serveur (jamais régénéré ici, sinon
# rotation des clés à chaque déploiement et invalidation des données chiffrées).
if [ -z "$APP_KEY" ]; then
    echo "❌ APP_KEY manquante : renseignez-la dans le .env du serveur avant de déployer." >&2
    exit 1
fi

# Les dépendances sont déjà bakées dans l'image. On régénère uniquement le
# manifeste des packages (nécessite le code + le .env présents au runtime).
php artisan package:discover --ansi || true

# Applique les migrations en attente, avec retry tant que la DB n'est pas prête
# (cache/queue/session sont sur le driver database).
n=0
until php artisan migrate --force; do
    n=$((n + 1))
    if [ "$n" -ge 40 ]; then
        echo "❌ Base de données injoignable après 40 tentatives (~120s)." >&2
        exit 1
    fi
    echo "⏳ DB pas prête, nouvelle tentative ($n/40)..."
    sleep 3
done

# Lien public/storage -> storage/app/public.
php artisan storage:link || true

# Caches optimisés pour la prod.
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo -E /usr/bin/supervisord -c /etc/supervisord.conf

exec "$@"
