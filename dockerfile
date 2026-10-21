FROM php:8.2-apache

# 1. Install system dependencies for OCR, PDF processing, and Image manipulation
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    libsodium-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    tesseract-ocr \
    tesseract-ocr-eng \
    poppler-utils \
 && rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mysqli mbstring sodium gd

# 3. Enable Apache modules for .htaccess
RUN a2enmod rewrite headers

# 4. Allow .htaccess overrides (Fix for security rules)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 5. Copy files and install Composer
COPY . /var/www/html/
WORKDIR /var/www/html

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader --no-scripts

# 6. Create storage and logs directories, set permissions
RUN mkdir -p /var/www/html/logs /var/www/enrollment_storage \
 && chown -R www-data:www-data /var/www/html /var/www/enrollment_storage \
 && chmod 700 /var/www/enrollment_storage

# 7. Bind Apache to Render's dynamic port
CMD sed -i "s/Listen 80/Listen ${PORT:-80}/g" /etc/apache2/ports.conf && apache2-foreground