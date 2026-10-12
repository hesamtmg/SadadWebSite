FROM php:8.3-apache
RUN a2enmod rewrite
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
COPY src ./src
COPY public ./public
RUN mkdir -p data && chown -R www-data:www-data data
ENV DATA_DIR=/var/www/html/data
EXPOSE 80
