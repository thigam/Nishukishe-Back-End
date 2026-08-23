# backend/Dockerfile
FROM php:8.2-apache

# 1. Install system dependencies
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
    libffi-dev \
    libzip-dev

# 2. Install PHP extensions (Including FFI and ZIP)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd ffi zip

# Install Redis PHP extension
RUN pecl install redis && docker-php-ext-enable redis


# 3. Apache Configuration
RUN a2enmod rewrite

# Point DocumentRoot to public/
ENV APACHE_DOCUMENT_ROOT /var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Enable .htaccess overrides (Fixes 404s)
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Enable FFI for H3 (Runtime Fix)
RUN echo "ffi.enable=true" > /usr/local/etc/php/conf.d/docker-php-ext-ffi.ini
RUN echo "extension=ffi.so" >> /usr/local/etc/php/conf.d/docker-php-ext-ffi.ini

# 4. Install H3 Library (Uber's Hexagonal Hierarchical Spatial Index)
WORKDIR /tmp
RUN git clone --branch v4.1.0 --depth 1 https://github.com/uber/h3.git \
    && cd h3 \
    && mkdir build \
    && cd build \
    && cmake .. -DBUILD_SHARED_LIBS=ON \
    && make \
    && make install \
    && ldconfig
RUN rm -rf /tmp/h3

# 5. Install Composer & Dependencies
WORKDIR /var/www
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock /var/www/

# Install dependencies (ignoring platform reqs for FFI during build just in case)
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts --ignore-platform-req=ext-ffi --prefer-source

# 6. Copy Application Code
COPY . /var/www/

# Dump autoload and set permissions
RUN composer dump-autoload --optimize
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Configure entrypoint to run MQTT worker in background
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

EXPOSE 80
CMD ["apache2-foreground"]
