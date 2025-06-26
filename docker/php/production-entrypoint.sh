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

sudo -E /usr/bin/supervisord -c /etc/supervisord.conf

exec "$@"