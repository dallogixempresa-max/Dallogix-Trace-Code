FROM cgr.dev/chainguard/wolfi-base:latest@sha256:fac38d12efdb4bf43ac9e599a31db10a27ad5dd71e5f1618790962eda8d66180

# Mantenha a linha PHP 8.3 e as bibliotecas já corrigidas no Scout.
RUN apk add --no-cache \
    libxml2=2.15.4-r0 \
    libxml2-16=2.15.4-r0 \
    libnghttp2-14=1.70.0-r4 \
    libpcre2-8-0=10.48-r0 \
    php-8.3=8.3.35-r0 \
    php-8.3-fpm=8.3.35-r0 \
    php-8.3-curl=8.3.35-r0 \
    php-8.3-ctype=8.3.35-r0 \
    php-8.3-dom=8.3.35-r0 \
    php-8.3-fileinfo=8.3.35-r0 \
    php-8.3-iconv=8.3.35-r0 \
    php-8.3-mbstring=8.3.35-r0 \
    php-8.3-mysqlnd=8.3.35-r0 \
    php-8.3-opcache=8.3.35-r0 \
    php-8.3-openssl=8.3.35-r0 \
    php-8.3-pdo=8.3.35-r0 \
    php-8.3-pdo_mysql=8.3.35-r0 \
    php-8.3-pdo_sqlite=8.3.35-r0 \
    php-8.3-posix=8.3.35-r0 \
    php-8.3-sodium=8.3.35-r0 \
    php-8.3-simplexml=8.3.35-r0 \
    php-8.3-xmlreader=8.3.35-r0 \
    php-8.3-xmlwriter=8.3.35-r0

RUN printf '%s\n' \
    'expose_php=Off' \
    'display_errors=Off' \
    'log_errors=On' \
    'upload_max_filesize=10M' \
    'post_max_size=12M' \
    'allow_url_fopen=Off' \
    'opcache.enable=1' \
    'opcache.enable_cli=0' \
    'opcache.memory_consumption=128' \
    'opcache.max_accelerated_files=10000' \
    'opcache.validate_timestamps=1' \
    'opcache.revalidate_freq=2' \
    > /etc/php/conf.d/zz-security.ini

RUN mkdir -p /var/lib/php/sessions && chown 82:82 /var/lib/php/sessions

WORKDIR /var/www/servidor
USER 82:82
CMD ["php-fpm", "--nodaemonize"]
