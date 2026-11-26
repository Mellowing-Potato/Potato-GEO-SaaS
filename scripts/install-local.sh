#!/bin/sh
set -eu
umask 077
fail() { printf '%s\n' "$1" >&2; exit 1; }
step() { printf '%s\n' "$1" >&2; }
usage() {
    printf '%s\n' 'install-local.sh --manifest SHA256SUMS --manifest-sha256 SHA [--package ARCHIVE] [--program-home DIR] [--instance DIR] [--port PORT] [--email EMAIL] [--password-file FILE] [--python-bin PYTHON] [--upgrade] [--no-browser]'
}
manifest= pin= package= program= instance= port= email= passfile= python= mode=install no_browser=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --help|-h) usage; exit 0;;
        --upgrade) mode=upgrade; shift; continue;;
        --no-browser) no_browser=1; shift; continue;;
        --manifest|--manifest-sha256|--package|--program-home|--instance|--port|--email|--password-file|--python-bin)
            [ "$#" -ge 2 ] || fail '选项需要一个值。';;
        *) fail '不支持此选项，请使用 --help。';;
    esac
    case "$1" in
        --manifest) manifest=$2;; --manifest-sha256) pin=$2;; --package) package=$2;;
        --program-home) program=$2;; --instance) instance=$2;; --port) port=$2;;
        --python-bin) python=$2;; --email) email=$2;; --password-file) passfile=$2;;
    esac
    shift 2
done
[ -n "$manifest" ] && [ "${#pin}" = 64 ] || fail '请提供发行清单及独立可信来源的 SHA-256。'
case "$pin" in *[!a-f0-9]*) fail '清单摘要必须为 64 位小写 SHA-256。';; esac
case "$(uname -s):$(uname -m)" in
    Darwin:arm64) platform=macos-arm64; base=$HOME/Library/Application\ Support/Yanji;;
    Linux:aarch64|Linux:arm64) platform=linux-aarch64; base=${XDG_DATA_HOME:-"$HOME/.local/share"}/yanji;;
    Linux:x86_64) platform=linux-x86_64; base=${XDG_DATA_HOME:-"$HOME/.local/share"}/yanji;;
    *) fail '该系统与架构没有经过本安装器验证的便携运行时，请参阅 PORTABLE.md。';;
esac
program=${program:-"$base/program"}
instance=${instance:-"$base/instance"}
case "$program" in /*) ;; *) fail '程序目录必须是绝对路径。';; esac
case "$instance" in /*) ;; *) fail '实例目录必须是绝对路径。';; esac
case "$instance/" in "$program/"*) fail '数据目录必须与程序目录分开。';; esac
case "$program/" in "$instance/"*) fail '程序目录必须与数据目录分开。';; esac
if command -v sha256sum >/dev/null 2>&1; then
    hash() { sha256sum < "$1" | awk '{print $1}'; }
    check_files() { sha256sum -c "$1" > /dev/null; }
else
    command -v shasum >/dev/null 2>&1 || fail '缺少系统 SHA-256 验证工具。'
    hash() { shasum -a 256 < "$1" | awk '{print $1}'; }
    check_files() { shasum -a 256 -c "$1" > /dev/null; }
fi
get() {
    case "$1" in
        https://*)
            if [ "${3:-}" = progress ] && [ -t 2 ]; then
                curl --fail --location --connect-timeout 30 --proto '=https' --proto-redir '=https' --output "$2" "$1"
            else
                curl --fail --silent --show-error --location --connect-timeout 30 --proto '=https' --proto-redir '=https' --output "$2" "$1"
            fi
            ;;
        *://*) fail '远端安装材料仅接受 HTTPS。';;
        *) [ -f "$1" ] || fail '缺少本地安装材料。'; cp "$1" "$2";;
    esac
}
scratch=$(mktemp -d)
stage=
cleanup() { rm -rf "$scratch"; if [ -n "$stage" ] && [ -d "$stage" ]; then chmod -R u+w "$stage"; rm -rf "$stage"; fi; }
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
case "$program" in /|*/../*|*/./*|*/..|*/.) fail '程序路径无效。';; esac
case "$instance" in /|*/../*|*/./*|*/..|*/.) fail '实例路径无效。';; esac
step '正在获取并校验发行清单...'
get "$manifest" "$scratch/sums"
[ "$(hash "$scratch/sums")" = "$pin" ] || fail '发行清单校验失败。'
entry() {
    awk -v name="$1" '$2==name && NF==2 && length($1)==64 && $1 !~ /[^a-f0-9]/ {print $1; n++} END {if(n!=1) exit 1}' "$scratch/sums"
}
bootstrap=$(entry install-local.sh) || fail '清单缺少唯一安装脚本摘要。'
if [ ! -f "$0" ] || [ "$(hash "$0")" != "$bootstrap" ]; then
    [ "$mode" = upgrade ] || fail '请先将匹配清单的安装脚本保存到本地，再执行。'
    case "$manifest" in */*) next=${manifest%/*}/install-local.sh;; *) next=install-local.sh;; esac
    step '正在获取目标版本的安装脚本...'
    get "$next" "$scratch/next.sh"
    [ "$(hash "$scratch/next.sh")" = "$bootstrap" ] || fail '候选安装器校验失败。'
    set -- /bin/sh "$scratch/next.sh" --upgrade --manifest "$manifest" --manifest-sha256 "$pin" --program-home "$program" --instance "$instance"
    if [ -n "$package" ]; then set -- "$@" --package "$package"; fi
    if [ "$no_browser" -eq 1 ]; then set -- "$@" --no-browser; fi
    "$@"
    exit $?
fi
name=yanji-$platform.tar.gz
digest=$(entry "$name") || fail '清单缺少该平台的唯一安装包摘要。'
if [ -z "$package" ]; then
    case "$manifest" in */*) package=${manifest%/*}/$name;; *) package=$name;; esac
fi
case "$package" in
    https://*) step "正在下载安装包 ${name}（文件较大，请稍候）...";;
    *) step "正在读取本地安装包 ${name}...";;
esac
get "$package" "$scratch/package" progress
step '正在校验安装包 SHA-256 与归档结构...'
[ "$(hash "$scratch/package")" = "$digest" ] || fail '安装包校验失败。'
# Release builders emit only regular files and directories with relative safe paths.
tar -tzf "$scratch/package" > "$scratch/names"
awk '/^\// || /(^|\/)\.\.(\/|$)/ || /[[:cntrl:]\\]/ {exit 1} END {if(!NR) exit 1}' "$scratch/names" || fail '安装包路径无效。'
tar -tvzf "$scratch/package" > "$scratch/types"
awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {exit 1}' "$scratch/types" || fail '安装包含链接或设备。'
# Resolve both physical destinations before changing directories supplied by the user.
# The checker and standalone CLI come directly from the already authenticated archive.
mkdir "$scratch/preflight"
tar -xzf "$scratch/package" -C "$scratch/preflight" runtime/bin/php app/portable/preflight.php app/portable/publish.php app/portable/src/Platform.php app/portable/src/Instance.php PACKAGE-FILES.sha256
"$scratch/preflight/runtime/bin/php" -n "$scratch/preflight/app/portable/preflight.php" "$program" "$instance" > "$scratch/paths"
{ IFS= read -r program; IFS= read -r instance; } < "$scratch/paths"
[ ! -L "$program" ] && [ ! -L "$program/releases" ] || fail '程序目录不能为符号链接。'
mkdir -p "$program"
if [ ! -f "$program/.yanji-program" ]; then
    (set -C; printf '%s\n' yanji-program-v1 > "$program/.yanji-program") 2>/dev/null || [ -f "$program/.yanji-program" ]
fi
mkdir -p "$program/releases"
program=$(cd "$program" && pwd -P)
release=$program/releases/$digest
if [ ! -d "$release" ]; then
    step '正在解压并部署程序文件...'
    stage=$(mktemp -d "$program/releases/.incoming.XXXXXXXX")
    tar -xzf "$scratch/package" -C "$stage"
    [ -x "$stage/runtime/bin/php" ] && [ -x "$stage/runtime/bin/frankenphp" ] && [ -f "$stage/app/portable/yanji.php" ] || fail '安装包布局无效。'
    printf '%s\n' "$digest" > "$stage/.archive-sha256"
    chmod -R a-w "$stage"
    "$scratch/preflight/runtime/bin/php" -n "$scratch/preflight/app/portable/publish.php" "$stage" "$release"
    if [ ! -d "$stage" ]; then stage=; fi
fi
[ ! -L "$release" ] && [ "$(cat "$release/.archive-sha256")" = "$digest" ] || fail '程序版本校验失败。'
# Always verify cached bytes with the inventory from the authenticated archive, never its cached copy.
[ -f "$scratch/preflight/PACKAGE-FILES.sha256" ] && [ ! -L "$scratch/preflight/PACKAGE-FILES.sha256" ] || fail '安装包缺少程序文件清单。'
cmp -s "$scratch/preflight/PACKAGE-FILES.sha256" "$release/PACKAGE-FILES.sha256" || fail '程序文件清单已更改。'
[ -z "$(find "$release" ! -type d ! -type f -print -quit)" ] || fail '程序目录包含未认证的链接或设备。'
step '正在核验程序文件完整性...'
(cd "$release" && check_files "$scratch/preflight/PACKAGE-FILES.sha256") || fail '程序字节与发行清单不同，请选择新的程序目录。'
expected=$(wc -l < "$scratch/preflight/PACKAGE-FILES.sha256")
actual=$(find "$release" -type f | wc -l)
[ "$actual" -eq "$((expected + 2))" ] || fail '程序目录存在额外文件，请选择新的程序目录。'
if [ "$mode" = upgrade ]; then
    step '正在执行实例升级...'
else
    step '正在初始化言及实例...'
fi
set -- "$release/runtime/bin/php" -n "$release/app/portable/yanji.php" "__$mode" --instance "$instance" --release "$release"
if [ -n "$port" ]; then set -- "$@" --port "$port"; fi
if [ -n "$email" ]; then set -- "$@" --email "$email"; fi
if [ -n "$python" ]; then set -- "$@" --python-bin "$python"; fi
if [ -n "$passfile" ]; then set -- "$@" --password-file "$passfile"; fi
if [ "$no_browser" -eq 1 ]; then set -- "$@" --no-browser; fi
"$@"
