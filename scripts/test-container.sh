#!/bin/sh
# Invoked only by the opt-in Docker test target. No application server/worker starts.
set -eu
cd /app
test "${APP_ENV:-}" = testing
test "${AI_MOCK_MODE:-}" = true
test ! -e .env
mkdir -p test-results
for executable in php node npm python3 pdftotext pdftoppm pdfimages pdfinfo pyftsubset; do
    command -v "$executable" >/dev/null
done
php -r 'foreach (["pdo_sqlite", "pdo_pgsql", "zip", "dom", "gd", "pcntl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'
python3 -c 'import docx, markdown, bs4, reportlab, weasyprint, pypdf'
python3 - <<'PY'
import importlib.util
from pathlib import Path

script = Path('skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py')
spec = importlib.util.spec_from_file_location('explainer_renderer', script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
missing = [f'{name}: {path}' for name, path in module.PACKAGED_FONTS.items() if not path.is_file()]
if missing:
    raise SystemExit('Report fonts missing from test/runtime image: ' + ', '.join(missing))
PY

case "${1:-}" in
    sqlite)
        export DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
        python3 -m unittest discover -s tests/python -p 'test_*font*.py'
        python3 -m unittest discover -s tests/python -p 'test_skill_snapshot.py'
        php artisan config:clear
        php_status=0
        js_status=0
        php vendor/bin/phpunit --do-not-cache-result --fail-on-skipped --log-junit test-results/phpunit-sqlite.xml || php_status=$?
        npm run test:js || js_status=$?
        printf 'SQLite PHP exit=%s; JavaScript exit=%s\n' "$php_status" "$js_status"
        test "$php_status" -eq 0 && test "$js_status" -eq 0
        ;;
    postgres)
        # Fixed disposable target; reject inherited connection strings/host settings.
        test "${DB_CONNECTION:-}" = pgsql
        test "${DB_HOST:-}" = postgres
        test "${DB_DATABASE:-}" = geo_test
        test "${DB_USERNAME:-}" = geo_test
        test -z "${DB_URL:-}"
        php artisan config:clear
        php vendor/bin/phpunit -c phpunit.postgres.xml --do-not-cache-result --fail-on-skipped --log-junit test-results/phpunit-postgres.xml
        ;;
    *) echo 'Expected sqlite or postgres.' >&2; exit 2 ;;
esac
