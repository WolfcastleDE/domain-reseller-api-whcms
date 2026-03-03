FROM php:8.2-cli

RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload

CMD ["vendor/bin/phpunit", "--colors=always"]
