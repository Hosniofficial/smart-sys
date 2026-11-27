# ==========================================
# SmartSys Backend - Production Dockerfile
# ==========================================

FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libsodium-dev \
    default-mysql-client \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers dir

# Suppress ServerName warning and disable directory slash redirects
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create .htaccess for production
# Note: CORS headers are handled by PHP middleware to avoid conflicts
RUN echo 'DirectorySlash Off\n\
DirectoryIndex index.php\n\
<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteCond %{REQUEST_FILENAME} !-f\n\
    RewriteCond %{REQUEST_FILENAME} !-d\n\
    RewriteRule ^ index.php [QSA,L]\n\
</IfModule>\n\
<IfModule mod_headers.c>\n\
    Header set X-Content-Type-Options "nosniff"\n\
    Header set X-Frame-Options "SAMEORIGIN"\n\
    Header set X-XSS-Protection "1; mode=block"\n\
</IfModule>' > /var/www/html/public/.htaccess

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create logs directory
RUN mkdir -p logs && chown -R www-data:www-data logs

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure Apache DocumentRoot to point to /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides and disable directory slash redirects
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Apache listens on the platform-provided $PORT (Railway injects it; defaults to 80 elsewhere).
# The vhost and ports.conf read APACHE_LISTEN_PORT, which docker/entrypoint.sh sets from $PORT.
ENV APACHE_LISTEN_PORT=80
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-ports.conf /etc/apache2/ports.conf

# Private (non-web) storage for uploaded attachments; the entrypoint can point it at a volume.
RUN mkdir -p storage/uploads public/uploads/logos \
    && chown -R www-data:www-data storage public/uploads \
    && chmod 750 storage/uploads

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port (informational; the real port is $PORT)
EXPOSE 80

# Liveness probe: no database dependency, so a database outage does not restart the container.
# (/health is the readiness endpoint and checks the database.)
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s \
  CMD curl -fsS "http://127.0.0.1:${PORT:-80}/check" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
