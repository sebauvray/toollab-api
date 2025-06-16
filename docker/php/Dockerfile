# Base PHP image
FROM php:8.2-fpm AS base

ARG CURRENT_USER \
    CURRENT_UID \
    TZ

WORKDIR /var/www

#TIMEZONE
RUN apt-get update && apt-get install -y tzdata
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# CREATE USER AND GROUP
RUN useradd -m -u $CURRENT_UID $CURRENT_USER
RUN usermod -a -G www-data $CURRENT_USER

RUN apt-get update && apt-get install -y \
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

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

USER $CURRENT_USER

# ========== STAGE DEV ==========
FROM base AS dev

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]

# ========== STAGE PROD ==========
FROM base AS prod

USER root

COPY . .

RUN chown -R $CURRENT_USER:$CURRENT_USER /var/www

USER $CURRENT_USER

RUN composer install

CMD ["php-fpm"]