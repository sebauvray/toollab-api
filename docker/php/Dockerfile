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
    && chmod 440 /etc/sudoers.d/$CURRENT_USER¨

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# ========== STAGE DEV ==========
FROM base AS dev

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER $CURRENT_USER

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ========== STAGE PROD ==========
FROM base AS prod

USER root

COPY . .

RUN chown -R $CURRENT_USER:$CURRENT_USER /var/www

# COPY THE SUPERVISORD CONFIG GENERATION SCRIPT AND EXECUTE IT
COPY docker/php/generate-supervisord-conf.sh /usr/local/bin/generate-supervisord-conf.sh
RUN chmod +x /usr/local/bin/generate-supervisord-conf.sh \
    && /usr/local/bin/generate-supervisord-conf.sh /etc/supervisord.conf $CURRENT_USER
    
COPY /docker/php/production-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER $CURRENT_USER

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]