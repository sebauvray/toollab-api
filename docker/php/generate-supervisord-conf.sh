#!/bin/bash
set -e

CONF_PATH=${1:-/etc/supervisord.conf}
USER_NAME=${2:-www-data}

cat <<EOF > "$CONF_PATH"
[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisord.log
logfile_maxbytes=50MB
logfile_backups=10
loglevel=debug
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=/usr/local/sbin/php-fpm
user=root
autostart=true
autorestart=true

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:listen --sleep=3 --tries=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${USER_NAME}
numprocs=8
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=10
stopwaitsecs=3600
EOF