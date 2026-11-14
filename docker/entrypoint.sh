#!/bin/sh
set -eu
trap 'exit 0' TERM INT
cd /app
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
case "${1:-web}" in
    web)
        export SERVER_NAME=":8000"
        export FRANKENPHP_CONFIG="num_threads 4
max_threads 8
max_requests 500"
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    worker|scheduler)
        role="$1"
        if [ "$role" = worker ]; then php artisan yanji:worker --check; fi
        echo '等待实例初始化；请运行 scripts/install.sh 或 yanji:install。'
        until php artisan yanji:ready --quiet; do sleep 5; done
        if [ "$role" = worker ]; then exec php artisan yanji:worker; fi
        exec php artisan schedule:work
        ;;
    *) exec "$@" ;;
esac
