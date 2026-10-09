# Образ приложения: php-fpm для сайта и тот же образ для Horizon, планировщика
# и тестов — один набор расширений на все процессы.
# Образец — real-estate.devstudio.ge/docker/php/Dockerfile (без Playwright:
# GS Home ничего не скрейпит, данные берёт из API Real Estate).
FROM php:8.3-fpm-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis \
        zip \
        xdebug \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-gshome.ini
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-gshome-pool.conf

WORKDIR /var/www/html
