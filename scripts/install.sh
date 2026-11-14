#!/bin/sh
# 可重复运行：保留已有 .env、数据卷、APP_KEY 和管理员。
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
command -v docker >/dev/null 2>&1 || { echo '请先安装 Docker Desktop 或 Docker Engine。' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Docker 尚未运行，请启动后重试。' >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo '需要 Docker Compose v2。' >&2; exit 1; }
if [ ! -f .env ]; then
    (umask 077; cp .env.example .env)
    echo '已生成 .env；可按 INSTALL.md 调整访问地址。'
fi
docker compose up -d --build
if docker compose exec -T web php artisan yanji:ready --quiet; then
    echo '已有管理员，保留账户和 APP_KEY。'
else
    if [ -t 0 ]; then
        docker compose exec web php artisan yanji:install "$@"
    else
        docker compose exec -T web php artisan yanji:install --no-interaction "$@"
    fi
fi
# Web 在初始化前已启动，需要重新加载持久密钥。后台服务会等待初始化
# 完成后才 exec 新进程；配置变更也已由 compose up 重建，无需再次重启。
# 避免刚 exec 的 PHP 尚未注册信号处理时收到 TERM，导致整段优雅退出等待。
docker compose restart web
echo '安装完成。请打开 .env 中 APP_URL 指定的地址，用管理员邮箱和密码登录。'
