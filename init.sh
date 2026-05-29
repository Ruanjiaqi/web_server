#!/bin/bash
# CRMEB 微信云托管容器初始化脚本
# 1. 将微信云托管环境变量转换为 CRMEB 所需格式
# 2. 修复目录/文件权限
# 3. 首次部署时执行自动安装
# 4. 启动 supervisord（管理 Nginx/PHP-FPM/队列/Workerman/定时）

set -e

echo "======================================="
echo "  CRMEB WeChat Cloud Init"
echo "======================================="

# ── 微信云托管环境变量适配 ────────────────────────────────
#
# 微信云托管注入的变量：
#   MYSQL_USERNAME   数据库用户名
#   MYSQL_PASSWORD   数据库密码
#   MYSQL_ADDRESS    host:port 格式（如 10.0.1.5:3306）
#
# CRMEB auto_install.php 读取的变量：
#   MYSQL_HOST_IP / MYSQL_PORT / MYSQL_USER / MYSQL_PASSWORD / MYSQL_DATABASE
#
# Redis（微信云托管可选服务）：
#   REDIS_ADDRESS    host:port 格式
#   REDIS_PASSWORD   密码（可为空）

if [ -n "${MYSQL_ADDRESS}" ]; then
    export MYSQL_HOST_IP="${MYSQL_ADDRESS%%:*}"
    export MYSQL_PORT="${MYSQL_ADDRESS##*:}"
fi

if [ -n "${MYSQL_USERNAME}" ] && [ -z "${MYSQL_USER}" ]; then
    export MYSQL_USER="${MYSQL_USERNAME}"
fi

if [ -n "${REDIS_ADDRESS}" ]; then
    export REDIS_HOST_IP="${REDIS_ADDRESS%%:*}"
    export REDIS_PORT="${REDIS_ADDRESS##*:}"
fi

# 设置默认值（本地调试时生效）
export MYSQL_HOST_IP="${MYSQL_HOST_IP:-127.0.0.1}"
export MYSQL_PORT="${MYSQL_PORT:-3306}"
export MYSQL_USER="${MYSQL_USER:-crmeb}"
export MYSQL_DATABASE="${MYSQL_DATABASE:-crmeb}"
export REDIS_HOST_IP="${REDIS_HOST_IP:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"
export REDIS_DATABASE="${REDIS_DATABASE:-0}"
export REDIS_PASSWORD="${REDIS_PASSWORD:-}"
export CRMEB_ADMIN_USER="${CRMEB_ADMIN_USER:-admin}"
export CRMEB_ADMIN_PWD="${CRMEB_ADMIN_PWD:-crmeb.com}"

echo "[INIT] MySQL  : ${MYSQL_HOST_IP}:${MYSQL_PORT}  DB=${MYSQL_DATABASE}"
echo "[INIT] Redis  : ${REDIS_HOST_IP}:${REDIS_PORT}  (容器内置)"
echo "[INIT] Admin  : ${CRMEB_ADMIN_USER}"

# ── 修复权限 ─────────────────────────────────────────────
echo "[INIT] Fixing permissions..."

for DIR in /var/www/backup /var/www/public /var/www/runtime; do
    [ ! -d "$DIR" ] && mkdir -p "$DIR"
    chmod 777 "$DIR"
done

for FILE in /var/www/.env /var/www/.version /var/www/.constant; do
    [ ! -f "$FILE" ] && touch "$FILE"
    chmod 666 "$FILE"
done

[ ! -d /var/www/public/uploads ] && mkdir -p /var/www/public/uploads
chmod -R 777 /var/www/public/uploads
chmod -R 777 /var/www/runtime 2>/dev/null || true

echo "[INIT] Permissions OK."

# ── 自动安装（仅首次）──────────────────────────────────────
LOCK_FILE="/var/www/public/install.lock"
INSTALL_SCRIPT="/var/www/public/install/auto_install.php"

if [ -f "${LOCK_FILE}" ]; then
    echo "[INIT] Already installed, skipping."
else
    if [ -f "${INSTALL_SCRIPT}" ]; then
        echo "[INIT] Running auto-install..."
        php "${INSTALL_SCRIPT}"
    else
        echo "[INIT] WARNING: auto_install.php not found, skipping."
    fi
fi

# ── 透传 QUEUE_NAME 给 supervisord 子进程 ─────────────────
# supervisord 的 program:queue 会直接从 .env 中读取，此处仅做日志
if [ -f /var/www/.env ]; then
    _Q=$(grep -E '^QUEUE_NAME\s*=' /var/www/.env 2>/dev/null | head -1 \
         | sed 's/^QUEUE_NAME\s*=\s*//' | tr -d ' \r\n')
    [ -n "$_Q" ] && echo "[INIT] Queue name: ${_Q}"
fi

# ── 启动所有服务 ──────────────────────────────────────────
echo "[INIT] Starting supervisord (nginx + php-fpm + queue + workerman + timer)..."
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
