# ── Stage 1: Node — build Vite/Tailwind frontend assets ─────────────────────
FROM node:20-alpine AS node-builder

WORKDIR /app

# Install Composer so we can populate vendor/ before Vite runs.
# Filament (and other packages) ship CSS/JS inside vendor/, and Vite imports
# those files at build time — without vendor/ the npm build fails with a
# "file not found" error for e.g. vendor/filament/filament/resources/css/theme.css.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer requires PHP; install a minimal runtime (no extensions needed here).
RUN apk add --no-cache php83 php83-phar php83-mbstring php83-openssl php83-json \
        php83-tokenizer php83-xml php83-xmlwriter php83-dom php83-curl \
    && ln -sf /usr/bin/php83 /usr/local/bin/php

# Install PHP deps first (better layer caching).
# --ignore-platform-reqs: this stage runs on alpine PHP 8.3, but composer.lock
# requires PHP 8.4 + several extensions (intl, simplexml, fileinfo, …). This
# vendor/ is NEVER executed here — it only gives Vite the Filament CSS files to
# import. The real runtime vendor/ is installed in the FrankPHP 8.4 stage below
# (with all extensions), so skipping the platform check here is safe.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# Install JS deps (separate layer so JS cache is not busted by PHP changes).
COPY package.json package-lock.json* ./
RUN npm ci

# Copy the full app source so Vite has access to vendor files (e.g. Filament CSS).
COPY . .

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
