FROM php:8.1-cli

# Instala extensiones necesarias
RUN docker-php-ext-install mysqli

# Copia todos los archivos al contenedor
COPY . /var/www/html
WORKDIR /var/www/html

# Puerto para el servidor
EXPOSE 80

# Comando por defecto
CMD ["php", "-S", "0.0.0.0:80", "index.php"]
