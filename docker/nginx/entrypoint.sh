#!/bin/sh
set -x

# Remplacer uniquement CONTAINER_NAME_API
envsubst '${CONTAINER_NAME_API}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

# Affiche le résultat pour debug
cat /etc/nginx/conf.d/default.conf

exec nginx -g 'daemon off;'