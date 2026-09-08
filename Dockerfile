FROM php:8.3-apache

ARG UID=1000
ARG GID=1000

# 1. Grab the magic pre-compiled extension installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# 2. Install FFmpeg and the MongoDB extension instantly (no compiling!)
RUN apt-get update && apt-get install -y \
    ffmpeg \
    unzip \
    git \
    && install-php-extensions mongodb \
    && rm -rf /var/lib/apt/lists/*

# 3. Synchronize permissions with your local user
RUN usermod -u ${UID} www-data && groupmod -g ${GID} www-data

# 4. Enable Apache rewrite module
RUN a2enmod rewrite

# 5. Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
