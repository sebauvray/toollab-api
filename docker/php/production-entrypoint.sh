#!/bin/sh
set -e

cd /var/www

sudo mkdir -p /var/www/storage/logs
sudo chown -R $CURRENT_USER:$CURRENT_USER /var/www/storage/logs
sudo chmod -R 777 /var/www/storage

sudo mkdir -p /var/log
sudo touch /var/log/supervisord.log
sudo chmod 644 /etc/supervisord.conf
sudo chown -R $CURRENT_USER:$CURRENT_USER /var/log
sudo chmod -R 755 /var/log

composer install --no-interaction --prefer-dist --optimize-autoloader

# APP_KEY doit être fourni via le .env du serveur (jamais régénéré ici, sinon
# rotation des clés à chaque déploiement et invalidation des données chiffrées).
if [ -z "$APP_KEY" ]; then
    echo "❌ APP_KEY manquante : renseignez-la dans le .env du serveur avant de déployer." >&2
    exit 1
fi

# Applique les migrations en attente (cache/queue/session sont sur le driver database).
php artisan migrate --force

# Lien public/storage -> storage/app/public (remplace l'étape CI cassée).
php artisan storage:link || true

# Caches optimisés pour la prod (relus uniquement après ce build).
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo -E /usr/bin/supervisord -c /etc/supervisord.conf

exec "$@"