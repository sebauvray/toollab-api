# Base PHP image
FROM php:8.2-fpm AS base

WORKDIR /var/www

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

# ========== STAGE DEV ==========
FROM base AS dev

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]

# ========== STAGE PROD ==========
FROM base AS prod

COPY . .

RUN composer install --no-dev --optimize-autoloader \
    && php artisan config:cache

CMD ["php-fpm"]