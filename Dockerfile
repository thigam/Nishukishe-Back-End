# Dockerfile
FROM php:8.2-apache
# Install system dependencies and build tools
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    cmake \
    build-essential \
    libffi-dev
# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd ffi
# Enable Apache mod_rewrite for Laravel
RUN a2enmod rewrite
# Configure Apache DocumentRoot to point to public/
ENV APACHE_DOCUMENT_ROOT /var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf
# Install H3 (Uber's Hexagonal Hierarchical Spatial Index)
WORKDIR /tmp
# CRITICAL: Pinning to v4.1.0 to match your PHP FFI bindings
RUN git clone --branch v4.1.0 --depth 1 https://github.com/uber/h3.git \
    && cd h3 \
    && mkdir build \
    && cd build \
    && cmake .. -DBUILD_SHARED_LIBS=ON \
    && make \
    && make install \
    && ldconfig
# Clean up
RUN rm -rf /tmp/h3
# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# Set working directory
WORKDIR /var/www
# Copy composer files first (for caching)
COPY composer.json composer.lock /var/www/
# Install dependencies (no scripts yet to avoid errors before code is present)
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts
# Copy application files (The rest of your code)
COPY . /var/www/
# Run post-autoload-dump scripts (now that code is present)
RUN composer dump-autoload --optimize
# Set permissions for Apache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
EXPOSE 80
CMD ["apache2-foreground"]
