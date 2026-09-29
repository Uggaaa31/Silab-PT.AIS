FROM php:8.2-apache

# Set non-interactive mode for apt
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies & libraries required for PHP extensions (GD, Zip, PDO MySQL) and default-mysql-client for auto-migration
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd zip opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Apache VirtualHost directory permissions
RUN echo '<Directory /var/www/html/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/labmineral.conf \
    && a2enconf labmineral

# Configure custom PHP settings
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
        echo 'date.timezone = Asia/Jakarta'; \
        echo 'session.cookie_httponly = 1'; \
    } > /usr/local/etc/php/conf.d/labmineral.ini

# Install Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application source code
COPY . /var/www/html/

# Copy and setup entrypoint script for auto-migration and startup
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure directories for dynamic uploads/exports exist and install composer dependencies
RUN mkdir -p /var/www/html/exports /var/www/html/scratch \
    && if [ -f "composer.json" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction || true; \
    fi \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/exports /var/www/html/scratch

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
