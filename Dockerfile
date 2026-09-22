# Use PHP 8.4 with Apache
FROM php:8.4-apache

# Install PostgreSQL client libraries and required PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache mod_rewrite for Laravel routing
RUN a2enmod rewrite

# Change Apache document root from /var/www/html to Laravel's /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Install PHP dependencies without dev packages
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set proper permissions for Laravel cache and storage
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose web server port
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
