FROM php:8.3-apache

# PHP razširitvi za MySQL (PDO + mysqli), mod_rewrite in produkcijski php.ini
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf "upload_max_filesize=40M\npost_max_size=45M\ndisplay_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n" > "$PHP_INI_DIR/conf.d/zz-scholix.ini" \
    && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Prepoved neposrednega dostopa do .env, .sql, .sh, .md, .yaml in Dockerfile
COPY docker/zasciti.conf /etc/apache2/conf-available/zasciti.conf
RUN a2enconf zasciti

WORKDIR /var/www/html

COPY . /var/www/html
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/prijava.php") === false ? 1 : 0);'