# ── Stage 1: Node — build Vite/Tailwind frontend assets ─────────────────────
FROM node:20-alpine AS node-builder

WORKDIR /app

# Install JS deps first (better layer caching).
COPY package.json package-lock.json* ./
RUN npm ci

# Copy only the files Vite needs to compile.
COPY vite.config.js ./
COPY resources/ ./resources/

# Compile assets into public/build/ (generates the manifest Vite requires).
RUN npm run build

# ── Stage 2: FrankPHP (PHP 8.4) — production image ───────────────────────────
# Worker/scheduler services reuse this same image and override the start
# command via the Procfile.
FROM dunglas/frankenphp:1-php8.4

# System packages required by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpq-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions. pdo_pgsql (Postgres), redis (queue/cache/Horizon/locks),
# pcntl + posix (Horizon signals, job timeouts, supervisor process control),
# bcmath (credit micro-USD math), intl (EN/HE formatting), gd (image touch-ups),
# the rest Laravel/Filament essentials. opcache for production throughput.
RUN install-php-extensions \
        intl zip pdo_pgsql gd bcmath pcntl posix sockets opcache redis

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# App source.
COPY . .

# Drop in the compiled frontend assets from the Node stage. This provides the
# Vite manifest (public/build/manifest.json) that Laravel requires at runtime —
# without it every page that calls vite() throws a 500.
COPY --from=node-builder /app/public/build ./public/build

RUN composer run-script post-autoload-dump --no-interaction 2>/dev/null || true

# Publish Filament's CSS/JS into public/ so the panels are styled. Without this
# /css/filament/* and /js/filament/* 404 in the browser (unstyled login/dashboard).
RUN php artisan filament:assets

ENV DB_CONNECTION=pgsql

# CRITICAL: a config cache baked at build time (no OPENROUTER_API_KEY / APP_KEY /
# TENANT_CREDENTIALS_KEY yet) leaves the AI client keyless and breaks every
# per-site credential decrypt at runtime. Always remove it; docker-web.sh and
# predeploy re-cache AFTER the env is present.
RUN rm -f bootstrap/cache/config.php

# Make entrypoints executable and storage/cache writable.
RUN chmod +x scripts/docker-web.sh scripts/predeploy.sh \
    && chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

EXPOSE 8080
CMD ["/bin/sh", "scripts/docker-web.sh"]
