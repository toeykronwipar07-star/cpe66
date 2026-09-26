FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

CMD sed -ri "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf \
    && sed -ri "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf \
    && a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork \
    && apache2-foreground
