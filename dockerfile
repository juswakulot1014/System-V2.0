FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql mysqli
COPY . /var/www/html/
WORKDIR /var/www/html
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader
EXPOSE 80