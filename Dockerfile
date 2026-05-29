# ============================================================
# CRMEB 微信云托管 Dockerfile
# 构建上下文：CRMEB 仓库根目录
# 运行时：PHP 7.4-FPM + Nginx + Supervisor（单容器）
# ============================================================

FROM php:7.4-fpm-alpine

# ── 时区 ─────────────────────────────────────────────────
RUN apk add --no-cache tzdata \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# ── 切换腾讯云 Alpine 镜像源 ──────────────────────────────
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.tencent.com/g' /etc/apk/repositories

# ── HTTPS 证书（微信云托管生命周期钩子需要 update-ca-certificates）──
RUN apk add --no-cache ca-certificates && update-ca-certificates

# ── 安装系统依赖 ──────────────────────────────────────────
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        redis \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libxml2-dev \
        curl-dev \
        imagemagick-dev \
        imagemagick \
        $PHPIZE_DEPS

# ── 安装 PHP 内置扩展 ─────────────────────────────────────
# mysqli: auto_install.php 使用 mysqli_connect()，必须安装
# pdo_mysql: ThinkPHP ORM 使用 PDO
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        gd \
        bcmath \
        mbstring \
        zip \
        opcache \
        intl \
        sockets \
        pcntl \
        posix \
        fileinfo \
        exif \
        xml \
        simplexml

# ── 安装 PECL 扩展（Redis、ImageMagick） ─────────────────
# imagick.so 在运行时需要 libgomp（imagemagick 的 OpenMP 依赖），
# 必须在删除构建工具之前显式保留，否则 libgomp.so.1 丢失导致扩展无法加载
RUN pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && apk del $PHPIZE_DEPS \
    && apk add --no-cache libgomp

# ── PHP 配置调整 ──────────────────────────────────────────
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo "upload_max_filesize=100M"; \
        echo "post_max_size=100M"; \
        echo "max_execution_time=300"; \
        echo "memory_limit=256M"; \
    } > /usr/local/etc/php/conf.d/crmeb.ini

# PHP-FPM 监听 TCP 127.0.0.1:9000（供 Nginx fastcgi_pass 连接）
RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|^;*listen\.owner.*|listen.owner = nobody|' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|^;*listen\.group.*|listen.group = nobody|' /usr/local/etc/php-fpm.d/www.conf

# ── 安装 Composer ─────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── 拷贝 CRMEB 应用代码 ───────────────────────────────────
# 构建上下文为仓库根目录，crmeb/ 即 PHP 应用源码
WORKDIR /var/www
COPY crmeb/ /var/www/

# 如 vendor 目录不存在则在线安装依赖
RUN if [ ! -d /var/www/vendor ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction --working-dir=/var/www; \
    fi

# ── 拷贝运行时配置 ────────────────────────────────────────
COPY nginx.conf       /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisor/supervisord.conf
COPY init.sh          /init.sh
RUN chmod +x /init.sh

# ── Nginx 全局配置补丁（关闭 daemon，输出到 stdout）────────
RUN sed -i '/^pid/d' /etc/nginx/nginx.conf \
    && sed -i '/^user/d' /etc/nginx/nginx.conf

# ── 预建目录及权限 ────────────────────────────────────────
RUN mkdir -p /var/www/runtime /var/www/backup /var/www/public/uploads \
    && chmod -R 777 /var/www/runtime /var/www/backup /var/www/public/uploads \
    && chmod 666 /var/www/.env /var/www/.version /var/www/.constant 2>/dev/null || true

# 对外暴露端口（与 container.config.json containerPort 一致）
EXPOSE 80

ENTRYPOINT ["/init.sh"]
