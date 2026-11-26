#!/bin/sh
set -eu
umask 077

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    printf '%s\n' "Usage: sh install.sh [--docker] [--program-home DIR] [--instance DIR] [--port PORT] [--manifest URL_OR_FILE --manifest-sha256 SHA] [--package FILE] [--upgrade] [--no-browser]"
    exit 0
fi

if [ "${1:-}" = "--docker" ]; then
    shift
    exec bash "$ROOT/scripts/install.sh" "$@"
fi

os_name=$(uname -s)
arch_name=$(uname -m)
if [ "$os_name" = "Darwin" ] && [ "$arch_name" = "x86_64" ]; then
    printf '%s\n' "检测到 Intel Mac（macos-x64），自动切换至 Docker 部署流程..." >&2
    exec bash "$ROOT/scripts/install.sh" "$@"
fi

case "$os_name:$arch_name" in
    Darwin:arm64) pkg_name='yanji-macos-arm64.tar.gz'; default_base="$HOME/Library/Application Support/Yanji" ;;
    Linux:aarch64|Linux:arm64) pkg_name='yanji-linux-aarch64.tar.gz'; default_base="${XDG_DATA_HOME:-$HOME/.local/share}/yanji" ;;
    Linux:x86_64|Linux:amd64) pkg_name='yanji-linux-x86_64.tar.gz'; default_base="${XDG_DATA_HOME:-$HOME/.local/share}/yanji" ;;
    *)
        printf '%s\n' "当前系统架构（${os_name}/${arch_name}）不在免 Docker 便携包范围内，请使用 bash scripts/install.sh 运行 Docker 版。" >&2
        exit 1
        ;;
esac

if command -v sha256sum >/dev/null 2>&1; then
    sha256_of() { sha256sum < "$1" | awk '{print $1}'; }
elif command -v shasum >/dev/null 2>&1; then
    sha256_of() { shasum -a 256 < "$1" | awk '{print $1}'; }
else
    printf '%s\n' "缺少系统 SHA-256 校验工具（sha256sum 或 shasum）。" >&2
    exit 1
fi

manifest=''
manifest_sha=''
custom_target=0
no_browser=0
upgrade=0
prev=''
for arg in "$@"; do
    case "$prev" in
        --manifest) manifest=$arg; prev=''; continue ;;
        --manifest-sha256) manifest_sha=$arg; prev=''; continue ;;
        --program-home|--instance|--port|--package) custom_target=1; prev=''; continue ;;
    esac
    case "$arg" in
        --manifest|--manifest-sha256|--program-home|--instance|--port|--package) prev=$arg ;;
        --manifest=*) manifest=${arg#*=} ;;
        --manifest-sha256=*) manifest_sha=${arg#*=} ;;
        --program-home=*|--instance=*|--port=*|--package=*) custom_target=1 ;;
        --upgrade) upgrade=1 ;;
        --no-browser) no_browser=1 ;;
    esac
done

if [ -z "$manifest" ] && [ -z "$manifest_sha" ]; then
    pin_file="$ROOT/portable/release-pin.json"
    [ -f "$pin_file" ] || { printf '%s\n' "缺少 portable/release-pin.json 发行摘要配置。" >&2; exit 1; }
    base_url=$(sed -n 's/.*"base_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$pin_file" | head -n 1 | sed 's:/$::')
    manifest_sha=$(sed -n 's/.*"manifest_sha256"[[:space:]]*:[[:space:]]*"\([a-f0-9]\{64\}\)".*/\1/p' "$pin_file" | head -n 1)
    case "$base_url" in
        https://*) ;;
        *) printf '%s\n' "发行地址必须使用 HTTPS。" >&2; exit 1 ;;
    esac
    manifest="$base_url/SHA256SUMS"
fi

case "$manifest_sha" in
    [a-f0-9][a-f0-9][a-f0-9][a-f0-9]*)
        [ "${#manifest_sha}" -eq 64 ] || { printf '%s\n' "请提供 64 位小写 SHA-256 清单摘要。" >&2; exit 1; }
        ;;
    *)
        printf '%s\n' "请同时提供 --manifest 与 64 位小写 --manifest-sha256。" >&2
        exit 1
        ;;
esac

tmp=$(mktemp -d)
cleanup() { rm -rf "$tmp"; }
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

printf '%s\n' "正在获取并校验言及发行清单..." >&2
case "$manifest" in
    https://*)
        curl --fail --silent --show-error --location --connect-timeout 30 --proto '=https' --proto-redir '=https' --output "$tmp/SHA256SUMS" "$manifest"
        ;;
    *://*)
        printf '%s\n' "远端安装材料仅接受 HTTPS。" >&2
        exit 1
        ;;
    *)
        [ -f "$manifest" ] || { printf '%s\n' "缺少本地发行清单：$manifest" >&2; exit 1; }
        cp "$manifest" "$tmp/SHA256SUMS"
        ;;
esac

[ "$(sha256_of "$tmp/SHA256SUMS")" = "$manifest_sha" ] || { printf '%s\n' "发行清单校验失败。" >&2; exit 1; }

pkg_sha=$(awk -v n="$pkg_name" '$2 == n {print $1}' "$tmp/SHA256SUMS")
installer_sha=$(awk '$2 == "install-local.sh" {print $1}' "$tmp/SHA256SUMS")
[ "${#pkg_sha}" -eq 64 ] && [ "${#installer_sha}" -eq 64 ] || { printf '%s\n' "发行清单缺少当前平台条目。" >&2; exit 1; }

if [ "$custom_target" -eq 0 ] && [ "$upgrade" -eq 0 ] && [ -x "$default_base/instance/yanji" ] && [ -f "$default_base/instance/.portable-initialized" ] && [ -L "$default_base/instance/current" ]; then
    current_rel=$(CDPATH= cd -- "$default_base/instance/current" 2>/dev/null && pwd -P || true)
    expected_rel=$(CDPATH= cd -- "$default_base/program/releases/$pkg_sha" 2>/dev/null && pwd -P || true)
    if [ -n "$current_rel" ] && [ "$current_rel" = "$expected_rel" ]; then
        printf '%s\n' "检测到已安装当前版本，正在直接启动言及工作台..." >&2
        if [ "$no_browser" -eq 1 ]; then
            exec "$default_base/instance/yanji" start --no-browser
        fi
        exec "$default_base/instance/yanji" open
    fi
fi

staged_installer="$tmp/install-local.sh"
if [ -f "$ROOT/scripts/install-local.sh" ] && [ "$(sha256_of "$ROOT/scripts/install-local.sh")" = "$installer_sha" ]; then
    cp "$ROOT/scripts/install-local.sh" "$staged_installer"
else
    printf '%s\n' "正在获取并校验安装脚本..." >&2
    case "$manifest" in
        https://*)
            installer_url="${manifest%/SHA256SUMS}/install-local.sh"
            curl --fail --silent --show-error --location --connect-timeout 30 --proto '=https' --proto-redir '=https' --output "$staged_installer" "$installer_url"
            ;;
        *)
            installer_local="$(dirname -- "$manifest")/install-local.sh"
            [ -f "$installer_local" ] || { printf '%s\n' "缺少匹配清单的 install-local.sh。" >&2; exit 1; }
            cp "$installer_local" "$staged_installer"
            ;;
    esac
    [ "$(sha256_of "$staged_installer")" = "$installer_sha" ] || { printf '%s\n' "安装脚本校验失败。" >&2; exit 1; }
fi

chmod 0700 "$staged_installer"
if [ ! -t 0 ] && (exec </dev/tty) 2>/dev/null; then
    /bin/sh "$staged_installer" --manifest "$manifest" --manifest-sha256 "$manifest_sha" "$@" </dev/tty
else
    /bin/sh "$staged_installer" --manifest "$manifest" --manifest-sha256 "$manifest_sha" "$@"
fi
