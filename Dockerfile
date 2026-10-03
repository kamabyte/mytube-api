# MyTube API и веб-клиент: Laravel (PHP-FPM) + nginx в одном контейнере
# (serversideup/php, S6 внутри). Образ ghcr.io/kamabyte/mytube-api собирает
# GitHub Actions (.github/workflows/image.yml), запускает Dokploy по
# deploy/compose.yml вместе с планировщиком и воркером mytube-workers.
#
# Данные — вне образа: база SQLite в /data, storage/ — том, медиатека —
# /media только на чтение (видео отдаёт nginx по
# X-Accel-Redirect, см. docker/nginx/mytube.conf).

# --- Фронтенд (Inertia + React + Vite) ------------------------------------
# Сборке PHP не нужен: laravel-vite-plugin только читает входные файлы.
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --loglevel=error
COPY vite.config.ts tsconfig.json components.json ./
COPY resources ./resources
RUN npm run build

# --- Приложение ------------------------------------------------------------
FROM serversideup/php:8.5-fpm-nginx-trixie
USER root
# intl — Number:: и локаль ru; bcmath, exif — как на прежнем сервере.
# sqlite3 — консистентная копия базы перед миграциями (entrypoint.d).
# ffmpeg — субтитры из MP4 в WebVTT для веб-плеера (App\Support\Subtitles).
RUN install-php-extensions intl bcmath exif \
 && apt-get update \
 && apt-get install -y --no-install-recommends sqlite3 ffmpeg \
 && rm -rf /var/lib/apt/lists/*
# uid/gid как у пользователя mytube на сервере (994:980): база и storage
# в /srv/mytube/data принадлежат ему, откат на нативный запуск не требует
# менять владельцев.
# В базовом образе 994 уже занят messagebus — освобождаем (он тут не используется).
RUN if getent passwd 994 >/dev/null && [ "$(getent passwd 994 | cut -d: -f1)" != www-data ]; then \
      usermod -u 60994 "$(getent passwd 994 | cut -d: -f1)"; fi \
 && if getent group 994 >/dev/null; then groupmod -g 60994 "$(getent group 994 | cut -d: -f1)"; fi \
 && docker-php-serversideup-set-id www-data 994:980 \
 && docker-php-serversideup-set-file-permissions --owner 994:980 --service nginx

COPY --chown=www-data:www-data docker/nginx/mytube.conf /etc/nginx/server-opts.d/mytube.conf
COPY --chmod=755 docker/entrypoint.d/45-mytube-db-backup.sh /etc/entrypoint.d/45-mytube-db-backup.sh

USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi

# Значения по умолчанию; всё, что про окружение, — в deploy/compose.yml.
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILE_SIZE=32M \
    PHP_POST_MAX_SIZE=32M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_FPM_PM_MAX_CHILDREN=8 \
    NGINX_CLIENT_MAX_BODY_SIZE=32M \
    HEALTHCHECK_PATH=/up \
    LOG_CHANNEL=stderr
