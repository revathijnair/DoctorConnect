FROM php:8.2-apache

# Install system dependencies and PHP PDO MySQL extension
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache to listen on non-privileged port 8080 (Required for OpenShift)
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# OpenShift arbitrary UID compliance: assign permissions to root group 0
RUN mkdir -p /var/run/apache2 /var/log/apache2 \
    && chgrp -R 0 /var/www /var/log/apache2 /var/run/apache2 \
    && chmod -R g=u /var/www /var/log/apache2 /var/run/apache2

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper group permissions for OpenShift
RUN chgrp -R 0 /var/www/html && chmod -R g=u /var/www/html

# Expose port 8080
EXPOSE 8080

# Run as non-root user (Required for OpenShift restricted-v2 SCC)
USER 1001

CMD ["apache2-foreground"]
