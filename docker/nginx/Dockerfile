# Contexte de build = racine du repo (permet de baker public/ dans le stage prod).
FROM fholzer/nginx-brotli:v1.26.2 as base


FROM base AS dev

COPY docker/nginx/dev.conf.template /etc/nginx/templates/default.conf.template
COPY docker/nginx/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]


FROM base AS prod

COPY docker/nginx/prod.conf.template /etc/nginx/templates/default.conf.template
COPY docker/nginx/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# public/ baké dans l'image : nginx sert le statique et résout SCRIPT_FILENAME
# (/var/www/public/index.php) pour le FastCGI vers php-fpm. En dev, le bind mount
# ./:/var/www le recouvre ; en prod immuable, c'est cette copie qui sert.
COPY public /var/www/public

ENTRYPOINT ["/entrypoint.sh"]
