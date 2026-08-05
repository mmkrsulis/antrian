FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends espeak-ng \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/queue.conf \
    && a2enconf queue

RUN printf 'upload_max_filesize=512M\npost_max_size=520M\nmax_execution_time=300\n' > /usr/local/etc/php/conf.d/queue-uploads.ini

COPY . /var/www/html
RUN mkdir -p storage/logs storage/sessions \
    && chown -R www-data:www-data storage

EXPOSE 80
