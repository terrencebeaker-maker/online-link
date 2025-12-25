# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# CRITICAL: Install PostgreSQL extensions in correct order
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring

# Verify extensions are loaded
RUN php -m | grep -i pdo

# Configure Apache to listen on port 10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && \
    sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

# Enable Apache modules
RUN a2enmod rewrite headers

# Copy project files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Create logs directory
RUN mkdir -p /var/www/html/logs && \
    chown -R www-data:www-data /var/www/html/logs && \
    chmod 777 /var/www/html/logs

# Link Apache logs to stdout/stderr
RUN ln -sf /dev/stdout /var/log/apache2/access.log \
    && ln -sf /dev/stderr /var/log/apache2/error.log

# Expose port 10000
EXPOSE 10000

# Start Apache
CMD ["apache2-foreground"]