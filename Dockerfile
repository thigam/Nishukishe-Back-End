# backend/Dockerfile
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
# Install H3 (Uber's Hexagonal Hierarchical Spatial Index)
WORKDIR /tmp
# CRITICAL: Pinning to v4.1.0 because your H3Wrapper.php uses v4 API (latLngToCell)
# If you use 'latest' (master), it might break if v5 is released.
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
# Copy existing application directory contents
COPY . /var/www
# Install dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev
# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
EXPOSE 9000
CMD ["php-fpm"]
