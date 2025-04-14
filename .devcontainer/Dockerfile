FROM php:8.2-apache

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Enable .htaccess files & mod_rewrite
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride all/' /etc/apache2/apache2.conf
RUN a2enmod rewrite

# Install required dependencies
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ghostscript \
        git \
        less \
        ssh-client \
        mariadb-client \
        libfreetype6-dev \
        libjpeg-dev \
        libpng-dev \
        libzip-dev \
        gnupg \
        curl; \
    # Clean up in the same layer to reduce image size
    rm -rf /var/lib/apt/lists/*

# Install Node.js and npm
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update \
    && apt-get install -y nodejs \
    && npm install -g npm@latest \
    && node -v \
    && npm -v \
    && rm -rf /var/lib/apt/lists/*

# Install and configure PHP extensions
RUN set -ex; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j "$(nproc)" \
        bcmath \
        exif \
        gd \
        mysqli \
        opcache \
        zip; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug

RUN mkdir -p /var/log/xdebug \
    && touch /var/log/xdebug/xdebug.log \
    && chmod 777 /var/log/xdebug \
    && chmod 666 /var/log/xdebug/xdebug.log \
    && chown -R www-data:www-data /var/log/xdebug

# Configure XDebug with more selective activation
RUN echo "[XDebug]\n\
xdebug.mode = debug,develop,coverage\n\
xdebug.start_with_request = yes\n\
xdebug.client_host = host.docker.internal\n\
xdebug.client_port = 9003\n\
xdebug.discover_client_host = false\n\
xdebug.log = /tmp/xdebug/xdebug.log" > $PHP_INI_DIR/conf.d/xdebug.ini \
    && mkdir -p /tmp/xdebug \
    && touch /tmp/xdebug/xdebug.log \
    && chmod -R 777 /tmp/xdebug

# WordPress recommended PHP settings
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Increase PHP memory limit
RUN echo "memory_limit = 256M" > $PHP_INI_DIR/conf.d/memory-limit.ini
RUN echo "upload_max_filesize = 64M" >> $PHP_INI_DIR/conf.d/memory-limit.ini
RUN echo "post_max_size = 64M" >> $PHP_INI_DIR/conf.d/memory-limit.ini

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp \
    && mkdir -p /var/www/.wp-cli/cache \
    && chown -R www-data:www-data /var/www/.wp-cli

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

# Set up VSCode user
RUN useradd -ms /bin/bash vscode \
    && usermod -aG www-data vscode

# Set up directory structure
WORKDIR /var/www/html

# Create plugin development directory
RUN mkdir -p /var/www/html/wp-content/plugins/plugin-dev

# Download WordPress as www-data user
USER www-data
RUN php -d memory_limit=512M /usr/local/bin/wp core download

# Fix permissions
USER root
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod g+w -R /var/www/html/ \
    && find /var/www/html/ -type d -exec chmod g+s {} \;

# Health check
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Set environment variables
ENV WP_CLI_CACHE_DIR=/var/www/.wp-cli/cache
ENV WP_CLI_DISABLE_AUTO_CHECK_UPDATE=true
ENV COMPOSER_ALLOW_SUPERUSER=1

# Expose port 80
EXPOSE 80

# Set default command
CMD ["apache2-foreground"]
