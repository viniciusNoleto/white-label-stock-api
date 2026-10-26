FROM php:8.5-fpm

WORKDIR /var/www/html

# Dependências do sistema (só o necessário para Laravel + PostgreSQL)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    locales \
    locales-all \
    libzip-dev \
    libpq-dev \
    libonig-dev \
    libicu-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Extensões do PHP (opcache já vem embutido no PHP 8.5)
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip mbstring bcmath intl pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Usuário com o mesmo uid/gid do host: arquivos criados pelo container (vendor, logs) continuam editáveis
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} www && useradd -u ${UID} -ms /bin/bash -g www www && chown -R www:www /var/www

ENV LC_ALL=pt_BR.UTF-8
ENV LANG=pt_BR.UTF-8
ENV LANGUAGE=pt_BR.UTF-8

USER www
RUN echo 'alias pa="php artisan "' >> ~/.bashrc

EXPOSE 9000

CMD ["php-fpm"]
