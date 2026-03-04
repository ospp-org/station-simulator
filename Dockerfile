FROM composer:2 AS deps

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-req=php

COPY . .
RUN composer dump-autoload --optimize

FROM php:8.4-cli-alpine

RUN apk add --no-cache openssl \
    && docker-php-ext-install pcntl

WORKDIR /app

COPY --from=deps /app /app

# Create keys directory
RUN mkdir -p /app/keys

ENV MQTT_HOST=emqx \
    MQTT_PORT=1883 \
    SIMULATOR_STATIONS=1 \
    SIMULATOR_AUTO_BOOT=true \
    LOG_LEVEL=info

EXPOSE 8085 8086

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["simulate"]
