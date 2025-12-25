# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Install system dependencies and PHP extensions
# IMPORTANT: Changed default-mysql-client to libpq-dev for PostgreSQL
# IMPORTANT: Changed pdo_mysql, mysqli to pdo_pgsql, pgsql for PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Apache to listen on port 10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && \
    sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy PHP files from www/ directory to Apache web root
# Assuming your PHP files (stkpush.php, config.php, etc.) are in a 'www/' folder
COPY www/ /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Log Apache output to Render logs
RUN ln -sf /dev/stdout /var/log/apache2/access.log \
    && ln -sf /dev/stderr /var/log/apache2/error.log

# Expose port 10000
EXPOSE 10000

# Start Apache
CMD ["apache2-foreground"]
