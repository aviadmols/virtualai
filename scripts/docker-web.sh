#!/bin/sh
# Web service entrypoint. FrankPHP serves Laravel's public/ via the Caddyfile.
set -eu

# Clear stale config cache (prevents OpenRouter-key / encryption-key mismatch).
rm -f bootstrap/cache/config.php 2>/dev/null || true

# Fail loudly if the app key is missing — the app cannot decrypt anything.
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set. Set it in Railway Variables (php artisan key:generate --show)." >&2
    exit 1
fi

# Normalize PORT (Railway injects it; guard against non-numeric).
PORT_NUM="${PORT:-8080}"
case "$PORT_NUM" in '' | *[!0-9]*) PORT_NUM=8080 ;; esac
export PORT="$PORT_NUM"

# Re-cache for runtime perf — SAFE now because the env is present.
php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true

# --- Background queue worker (single-container fallback) ----------------------
# Until a dedicated `worker` Railway service runs Horizon, process the queues here
# so scans/generations don't pile up. A respawn loop self-heals a crashed worker.
# --timeout 110 stays under the redis retry_after (120) to avoid double-processing.
# Disable once a dedicated worker exists by setting WEB_INLINE_WORKER=false.
if [ "${WEB_INLINE_WORKER:-true}" != "false" ]; then
    (
        while true; do
            php artisan queue:work redis \
                --queue=generations,scans,webhooks,media,default \
                --sleep=3 --tries=3 --timeout=110 --max-time=3600 || true
            sleep 2
        done
    ) &
fi

# exec so FrankPHP is PID 1 and receives Railway's SIGTERM for graceful shutdown.
exec frankenphp run --config Caddyfile
