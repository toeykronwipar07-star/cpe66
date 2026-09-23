FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

CMD sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT:-/var/www/html}!g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf \
    && sed -ri "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground