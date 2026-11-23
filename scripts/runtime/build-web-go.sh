#!/bin/sh
# Upstream v1.13.6's without-xcaddy entry point, linked to a separately built ZTS embed SAPI.
# Supply CGO_CFLAGS/CGO_LDFLAGS and a platform linker with the corresponding native inputs.
set -eu
[ "$#" -eq 2 ] || { echo 'Usage: build-web-go.sh FRANKENPHP_SOURCE OUTPUT' >&2; exit 2; }
source_dir=$(cd "$1" && pwd -P)
case "$2" in /*) output=$2;; *) output=$PWD/$2;; esac
: "${CGO_CFLAGS:?Set C compiler flags for the ZTS embed headers}"
: "${CGO_LDFLAGS:?Set linker flags for the corresponding static libraries}"
export GOWORK=off GOTOOLCHAIN=local CGO_ENABLED=1
[ "$(go env GOVERSION)" = go1.27.4 ] || { echo 'Use the pinned Go 1.27.4 toolchain.' >&2; exit 1; }
python3 - "$source_dir" <<'PY'
import hashlib
from pathlib import Path
import sys
root = Path(sys.argv[1])
expected = {
    'go.mod': '7c177a2ebb4e67622508774c6fe31a9c61f1750d221c673305eabfa6e01d32dd',
    'go.sum': 'b31a29ae1684878fca2306bd61ed531e7ead74235acad931c3653ccfb0207f6c',
    'caddy/go.mod': 'c6e95bcf2214024a176070fbe8e670cc4618cddce609bff8d6babc1439a29e6b',
    'caddy/go.sum': 'c86fe7566f9d2aaf164f1bb51bda27c768ce7f56f37c9004f1119cb4455d2094',
}
for name, sha in expected.items():
    if hashlib.sha256((root / name).read_bytes()).hexdigest() != sha:
        raise SystemExit('FrankenPHP upstream dependency lock changed: ' + name)
PY
cd "$source_dir/caddy"
tags=nomercure,novulcain,nowatcher,nobadger,nomysql,nopgx
modules=$(go list -mod=readonly -deps -tags="$tags" -f '{{with .Module}}{{.Path}} {{.Version}}{{end}}' ./frankenphp)
if printf '%s\n' "$modules" | grep -Eq '^github\.com/dunglas/(mercure|vulcain)([/ ]|$)'; then
    echo 'The active module graph contains a disabled integration.' >&2
    exit 1
fi
printf '%s\n' "$modules" | grep -Fxq 'github.com/dunglas/caddy-cbrotli v1.0.1'
printf '%s\n' "$modules" | grep -Fxq 'github.com/caddyserver/caddy/v2 v2.11.9'
link_flags="-linkmode=external -extld='${WEB_LINKER:-cc}' -X github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp"
link_flags="$link_flags -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.13.6 PHP 8.5.13 Caddy'"
link_flags="$link_flags -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'"
go build -mod=readonly -p="${WEB_JOBS:-2}" -trimpath -buildvcs=false -buildmode=pie \
    -tags="$tags" -ldflags="$link_flags" -o "$output" ./frankenphp
