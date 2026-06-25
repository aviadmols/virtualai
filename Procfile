# Three services, one repo, one image. Provision each as a SEPARATE Railway
# service from this repo with the matching process. Worker/scheduler must NOT
# have an HTTP healthcheck (they have no listening port).
#
#   web        -> FrankPHP/Caddy: Filament panels + public widget API + signed-media redirects.
#   worker     -> Horizon: generations, scans, webhooks, media, default queues.
#   scheduler  -> schedule:work: heartbeat + media-retention purge dispatch. EXACTLY 1 replica.
#
# `exec` makes the PHP process PID 1 so Railway's SIGTERM drains in-flight jobs
# gracefully (a draining image generation can take ~60s). `rm -f` the config
# cache on boot so a build-time cache without OPENROUTER_API_KEY/APP_KEY can't
# break the AI client or per-site credential decrypts.
web: /bin/sh scripts/docker-web.sh
worker: /bin/sh -c 'rm -f bootstrap/cache/config.php 2>/dev/null; exec php artisan horizon'
scheduler: /bin/sh -c 'rm -f bootstrap/cache/config.php 2>/dev/null; exec php artisan schedule:work'
