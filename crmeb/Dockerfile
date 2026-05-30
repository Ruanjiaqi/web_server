FROM php:7.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql bcmath gd zip mbstring pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY container/nginx.conf /etc/nginx/conf.d/default.conf
COPY container/start.sh /usr/local/bin/crmeb-start
RUN chmod +x /usr/local/bin/crmeb-start

EXPOSE 80

CMD ["crmeb-start"]
