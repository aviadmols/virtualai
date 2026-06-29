#!/bin/sh
# Pre-deploy guard + migrations. Runs ONCE before the new version takes traffic.
# Keep idempotent and fail fast on dangerous misconfiguration.
set -eu

# === CONSTANTS ===
SERVICE="${RAILWAY_SERVICE_NAME:-web}"   # only `web` runs migrations (canonical migrator)

# Clear any baked config cache BEFORE checking — a build-time cache without
# OPENROUTER_API_KEY / APP_KEY / TENANT_CREDENTIALS_KEY would mask a real failure
# and break the AI client + per-site credential decrypts at runtime.
rm -f bootstrap/cache/config.php 2>/dev/null || true

# Fail-closed env/config guard. Exits non-zero on any critical misconfiguration.
php artisan trayon:predeploy-check

# Migrate ONLY from the web service. This script is shared by web, worker and
# scheduler; running `migrate` from all three concurrently can race (two
# processes creating the same table -> one fails the deploy). web is the
# canonical migrator; worker/scheduler skip it. Defaults to running when
# RAILWAY_SERVICE_NAME is unset (local) so local deploys still migrate.
if [ "$SERVICE" = "web" ]; then
    php artisan migrate --force

    # Bootstrap/refresh the platform super-admin from env, if provided. No-op when
    # TRAYON_SUPERADMIN_EMAIL / TRAYON_SUPERADMIN_PASSWORD are unset. Idempotent; web-only.
    php artisan trayon:make-super-admin
else
    echo "predeploy: $SERVICE — skipping migrate (web is the canonical migrator)"
fi

# Re-cache for runtime perf — SAFE now because the env is present.
php artisan config:cache || true
php artisan event:cache || true

echo "predeploy: ok"
