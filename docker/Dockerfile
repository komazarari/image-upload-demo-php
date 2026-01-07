# Stage 1: Development
FROM php:8.2-apache AS development

# Install build dependencies
RUN apt-get update && apt-get install -y \
    imagemagick \
    libmagickwand-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    git \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy only dependency files for better caching
COPY composer.json composer.lock ./

# Install PHP extensions
RUN pecl install imagick && docker-php-ext-enable imagick
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install -j$(nproc) gd

# Install dependencies including dev
RUN composer install --no-interaction --optimize-autoloader

# Copy application code
COPY src src
COPY public public

# Create storage directories
RUN mkdir -p storage/uploads storage/logs

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/storage
RUN chmod -R 755 /var/www/html/storage

# Configure Apache
RUN a2enmod rewrite
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf
RUN echo "<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
    <IfModule mod_rewrite.c>\n\
        RewriteEngine On\n\
        RewriteBase /\n\
        RewriteCond %{REQUEST_FILENAME} !-f\n\
        RewriteCond %{REQUEST_FILENAME} !-d\n\
        RewriteRule ^ index.php [QSA,L]\n\
    </IfModule>\n\
</Directory>" > /etc/apache2/conf-available/app.conf && \
    a2enconf app

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]


# Stage 2: Builder (for production build)
FROM php:8.2-apache AS builder

# Install build dependencies
RUN apt-get update && apt-get install -y \
    imagemagick \
    libmagickwand-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    git \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /build

# Copy only dependency files for better caching
COPY composer.json composer.lock ./

# Install PHP extensions needed for build
RUN pecl install imagick && docker-php-ext-enable imagick
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install -j$(nproc) gd

# Install dependencies (without dev)
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Copy application code
COPY src src
COPY public public


# Stage 3: Production
FROM php:8.2-apache AS production

# Install only runtime system libraries
RUN apt-get update && apt-get install -y \
    imagemagick \
    libmagickwand-6.q16 \
    libfreetype6 \
    libjpeg62-turbo \
    libpng16-16 \
    libwebp7 \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP extensions from builder stage
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

# Set working directory
WORKDIR /var/www/html

# Copy built application and dependencies from builder stage
COPY --from=builder /build/vendor vendor
COPY --from=builder /build/src src
COPY --from=builder /build/public public

# Create storage directories
RUN mkdir -p storage/uploads storage/logs

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/storage
RUN chmod -R 755 /var/www/html/storage

# Configure Apache
RUN a2enmod rewrite

# Update document root
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# Add Apache configuration for URL rewriting
RUN echo "<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
    <IfModule mod_rewrite.c>\n\
        RewriteEngine On\n\
        RewriteBase /\n\
        RewriteCond %{REQUEST_FILENAME} !-f\n\
        RewriteCond %{REQUEST_FILENAME} !-d\n\
        RewriteRule ^ index.php [QSA,L]\n\
    </IfModule>\n\
</Directory>" > /etc/apache2/conf-available/app.conf && \
    a2enconf app

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
