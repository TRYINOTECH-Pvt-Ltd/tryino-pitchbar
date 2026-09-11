#!/bin/sh
set -eu

role="${1:-web}"

log() {
    echo "[pitchbar-entrypoint] $*"
}

require_env() {
    eval "val=\${$1:-}"
    if [ -z "$val" ]; then
        log "FATAL: $1 is not set. Copy .env.production.example → .env and fill it in."
        exit 1
    fi
}

wait_for_tcp() {
    host="$1"
    port="$2"
    name="$3"
    i=0
    while [ "$i" -lt 60 ]; do
        if php -r "exit(@fsockopen('${host}', ${port}, \$e, \$s, 2) ? 0 : 1);"; then
            log "${name} is reachable at ${host}:${port}"
            return 0
        fi
        i=$((i + 1))
        log "waiting for ${name} (${host}:${port})… ${i}/60"
        sleep 2
    done
    log "FATAL: ${name} did not become reachable"
    exit 1
}

prepare_storage() {
    mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache || true
}

run_web_boot() {
    require_env APP_KEY

    if [ "${WIDGET_JWT_SECRET:-change-me-in-production}" = "change-me-in-production" ] \
        || [ -z "${WIDGET_JWT_SECRET:-}" ]; then
        log "WARN: WIDGET_JWT_SECRET is still the default. Generate one with: openssl rand -hex 32"
    fi

    php artisan storage:link --force >/dev/null 2>&1 || php artisan storage:link || true

    # Schema only so /register and Settings → System can load.
    # Do not seed users or plans — the in-app installer owns that
    # (first signup creates a Free plan on demand; System page owns keys).
    php artisan migrate --force --no-interaction

    php artisan optimize
}

prepare_storage

if [ "$role" != "ssr" ]; then
    wait_for_tcp "${DB_HOST:-postgres}" "${DB_PORT:-5432}" "postgres"
    wait_for_tcp "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "redis"
fi

case "$role" in
    web)
        run_web_boot
        workers="${OCTANE_WORKERS:-auto}"
        max_requests="${OCTANE_MAX_REQUESTS:-500}"
        caddyfile="${OCTANE_CADDYFILE:-/app/infra/docker/Caddyfile}"
        log "starting Octane/FrankenPHP (workers=${workers}, max-requests=${max_requests})"
        exec php artisan octane:frankenphp \
            --host=0.0.0.0 \
            --port=80 \
            --admin-port=2019 \
            --workers="${workers}" \
            --max-requests="${max_requests}" \
            --caddyfile="${caddyfile}" \
            --log-level="${OCTANE_LOG_LEVEL:-info}"
        ;;
    horizon)
        log "starting Horizon"
        exec php artisan horizon
        ;;
    scheduler)
        log "starting scheduler loop"
        while true; do
            php artisan schedule:run --no-interaction || log "WARN: schedule:run exited non-zero"
            sleep 60
        done
        ;;
    reverb)
        log "starting Reverb on 0.0.0.0:8080"
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    ssr)
        log "starting Inertia SSR on 0.0.0.0:13714"
        exec node bootstrap/ssr/ssr.js
        ;;
    *)
        log "unknown role '$role' — expected web|horizon|scheduler|reverb|ssr"
        exec "$@"
        ;;
esac
