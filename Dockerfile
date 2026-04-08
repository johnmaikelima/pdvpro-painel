FROM php:8.3-apache

# Extensoes necessarias
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Document root no /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Suprimir aviso ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Habilitar exibicao de erros para debug (remover depois)
RUN echo "php_value display_errors 1" >> /var/www/html/public/.htaccess 2>/dev/null; true

# Copiar projeto
COPY . /var/www/html/

# Permissoes
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
