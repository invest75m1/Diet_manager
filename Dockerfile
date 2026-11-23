# Diet Manager – kontajner pre cloud (Render, Railway, Fly.io, VPS …)
FROM composer:2 AS vendor
WORKDIR /build/app
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libsqlite3-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite gd exif \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && printf 'upload_max_filesize=16M\npost_max_size=20M\nmax_execution_time=180\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www/html
COPY public ./public
COPY app ./app
COPY --from=vendor /build/app/vendor ./app/vendor
RUN mkdir -p /data && chown -R www-data:www-data /data app/storage
# Dáta (SQLite, fotky, zálohy) sú v /data – pripojte trvalý disk (volume)
ENV DM_STORAGE_DIR=/data
VOLUME ["/data"]
EXPOSE 80
