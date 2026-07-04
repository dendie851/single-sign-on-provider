FROM php:8.2-apache

# Install ekstensi yang diperlukan
RUN docker-php-ext-install pdo pdo_mysql && \
    docker-php-ext-enable pdo pdo_mysql

# Aktifkan mod_rewrite untuk Apache (berguna jika ingin URL routing)
RUN a2enmod rewrite

# Copy source code aplikasi ke document root Apache
COPY ./src/ /var/www/html/

# Set permission yang aman
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Konfigurasi PHP Session untuk keamanan
RUN echo "session.cookie_httponly = 1" >> /usr/local/etc/php/conf.d/security.ini && \
    echo "session.cookie_secure = 0" >> /usr/local/etc/php/conf.d/security.ini && \
    echo "session.use_only_cookies = 1" >> /usr/local/etc/php/conf.d/security.ini && \
    echo "session.cookie_samesite = Lax" >> /usr/local/etc/php/conf.d/security.ini

EXPOSE 80