# Imagen del API de la Pensión Doña Roberta (Laravel 10 + PHP-FPM)
FROM php:8.2-fpm-alpine

RUN apk add --no-cache postgresql-dev sqlite-dev libzip-dev icu-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite zip intl opcache bcmath

# Imágenes de QR de hasta 4 MB (validación en PaymentController)
RUN echo "upload_max_filesize=8M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias primero para aprovechar la caché de capas
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN touch .env && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
