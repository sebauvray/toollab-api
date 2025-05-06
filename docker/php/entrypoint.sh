#!/bin/bash

echo "🎶 DEV mode: Installing dependencies..."

# Load variables from the .env file into the shell environment
if [ -f .env ]; then
    echo "🔄 Loading variables from .env into the environment..."
    export $(grep -v '^#' .env | xargs)
fi

# Install PHP dependencies at each startup
composer install

# Check if APP_KEY is set in .env (reading the file directly)
APP_KEY_VALUE=$(grep ^APP_KEY= .env | cut -d '=' -f2-)

if [ -z "$APP_KEY_VALUE" ]; then
    echo "🔑 APP_KEY not found in .env, generating a new one..."
    php artisan key:generate
else
    echo "✅ APP_KEY is already set in .env."
fi

# Check if CONTAINER_NAME_DB and DB_DATABASE are set
if [ -n "$CONTAINER_NAME_DB" ] && [ -n "$DB_DATABASE" ]; then
    echo "🔎 Checking existence of the database '$DB_DATABASE' on host '$CONTAINER_NAME_DB'..."

    # Test if the database exists
    mysql -h"${CONTAINER_NAME_DB}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "USE \`${DB_DATABASE}\`;"

    if [ $? -eq 0 ]; then
        echo "✅ The database '$DB_DATABASE' exists."

        # Count the number of tables in the database
        TABLE_COUNT=$(mysql -h"${CONTAINER_NAME_DB}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -D "${DB_DATABASE}" -sse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_DATABASE}';")

        echo "📊 The database '$DB_DATABASE' contains $TABLE_COUNT tables."

        if [ "$TABLE_COUNT" -eq 0 ]; then
            echo "⚠️ No tables found, running migrations..."
            php artisan migrate --force
        else
            echo "✅ Tables are already present, no migration needed."
        fi
    else
        echo "⚠️ The database '$DB_DATABASE' does not exist. Laravel will handle this if needed."
    fi
else
    echo "⚠️ Variables CONTAINER_NAME_DB or DB_DATABASE are not set, skipping DB check."
fi

# Clear and regenerate config cache (useful in dev)
php artisan config:clear
php artisan config:cache

echo "✅ DEV ready."

# Start php-fpm
exec php-fpm