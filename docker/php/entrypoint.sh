#!/bin/bash

echo "🎶 DEV mode: Installing dependencies..."

# Charger les variables du .env dans l'environnement shell
if [ -f .env ]; then
    echo "🔄 Chargement des variables du .env dans l'environnement..."
    export $(grep -v '^#' .env | xargs)
fi

# Installer les dépendances PHP à chaque démarrage
composer install

# Vérifier si APP_KEY est définie dans le .env (en lisant directement le fichier)
APP_KEY_VALUE=$(grep ^APP_KEY= .env | cut -d '=' -f2-)

if [ -z "$APP_KEY_VALUE" ]; then
    echo "🔑 APP_KEY non trouvée dans .env, génération en cours..."
    php artisan key:generate
else
    echo "✅ APP_KEY déjà définie dans .env."
fi

# Vérifier si DB_HOST et DB_DATABASE sont définis
if [ -n "$CONTAINER_NAME_DB" ] && [ -n "$DB_DATABASE" ]; then
    echo "🔎 Vérification de l'existence de la base '$DB_DATABASE' sur l'hôte '$CONTAINER_NAME_DB'..."

    # Tester si la DB existe
    mysql -h"${CONTAINER_NAME_DB}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "USE \`${DB_DATABASE}\`;"

    if [ $? -eq 0 ]; then
        echo "✅ La base '$DB_DATABASE' existe."

        # Compter le nombre de tables dans la base
        TABLE_COUNT=$(mysql -h"${CONTAINER_NAME_DB}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -D "${DB_DATABASE}" -sse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_DATABASE}';")

        echo "📊 La base '$DB_DATABASE' contient $TABLE_COUNT tables."

        if [ "$TABLE_COUNT" -eq 0 ]; then
            echo "⚠️ Aucune table trouvée, lancement des migrations..."
            php artisan migrate --force
        else
            echo "✅ Tables déjà présentes, aucune migration nécessaire."
        fi
    else
        echo "⚠️ La base '$DB_DATABASE' n'existe pas. Laravel se chargera de gérer ça si nécessaire."
    fi
else
    echo "⚠️ Variables DB_HOST ou DB_DATABASE non définies, saut de la vérification DB."
fi

# Clear et regen cache config (utile en dev)
php artisan config:clear
php artisan config:cache

echo "✅ DEV ready."

# Lancer php-fpm
exec php-fpm