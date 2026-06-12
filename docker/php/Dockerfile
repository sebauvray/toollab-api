# ========== BASE ==========
# Socle commun dev / vendor / prod : PHP + extensions + utilisateur + composer
FROM php:8.2-fpm AS base

ARG CURRENT_USER \
    CURRENT_UID \
    TZ

WORKDIR /var/www

#TIMEZONE
RUN apt-get update && apt-get install -y tzdata
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# CREATE USER AND GROUP
RUN useradd -m -u $CURRENT_UID $CURRENT_USER \
    && usermod -a -G www-data $CURRENT_USER \
    && usermod -a -G $CURRENT_USER www-data

RUN apt-get update && apt-get install -y \
    sudo \
    supervisor \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    libzip-dev \
    default-mysql-client \
    && docker-php-ext-configure zip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# CONFIGURE SUDOERS ONLY FOR SUPERVISOR
RUN echo "$CURRENT_USER ALL=(ALL) NOPASSWD:SETENV: ALL" > /etc/sudoers.d/$CURRENT_USER \
    && chmod 440 /etc/sudoers.d/$CURRENT_USER

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# ========== STAGE DEV ==========
# Code + vendor montés au runtime (bind mount + volume nommé) ; l'entrypoint
# lance `composer install` puis php-fpm.
FROM base AS dev

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Pré-crée vendor avec le bon propriétaire : au premier montage, le volume nommé
# hérite de cet ownership (sinon il serait root et composer install échouerait).
RUN mkdir -p /var/www/vendor && chown -R $CURRENT_USER:$CURRENT_USER /var/www

USER $CURRENT_USER

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ========== STAGE VENDOR ==========
# Installe les dépendances prod à partir des seuls composer.json/lock
# (--no-scripts car le code applicatif n'est pas encore présent).
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --optimize-autoloader

# ========== STAGE PROD ==========
# Image immuable : code applicatif + vendor bakés, aucun montage du code.
FROM base AS prod

# Conf supervisord (php-fpm + worker queue)
COPY docker/php/generate-supervisord-conf.sh /usr/local/bin/generate-supervisord-conf.sh
RUN chmod +x /usr/local/bin/generate-supervisord-conf.sh \
    && /usr/local/bin/generate-supervisord-conf.sh /etc/supervisord.conf $CURRENT_USER

# Code applicatif (le .dockerignore exclut .env, vendor, node_modules, .git…)
COPY . .
# Dépendances pré-installées depuis le stage vendor
COPY --from=vendor /var/www/vendor ./vendor
# Régénère l'autoload optimisé (classes de l'app) + le manifeste des packages
# (package:discover) sans les dépendances de dev. COMPOSER_ALLOW_SUPERUSER car
# ce stage build tourne encore en root (USER plus bas).
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev \
    && chown -R $CURRENT_USER:$CURRENT_USER /var/www

COPY docker/php/production-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER $CURRENT_USER

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
