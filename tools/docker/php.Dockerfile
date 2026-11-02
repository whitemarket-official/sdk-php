# PHP runtime used by `make matrix`, `make coverage` and `make infection`:
# the official CLI image plus the pcov coverage driver (the host PHP has none).
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install pcov \
    && docker-php-ext-enable pcov

# The optional JSON stream decoders of ExportClient::streamProducts() (ADR 0006), so the matrix and coverage run the
# tests of every decoder; the host PHP (no extensions) runs the json-machine fallback. Versions pinned.
COPY --from=mlocati/php-extension-installer:2.13.1 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions simdjson-4.0.0 yajl_stream-0.1.0

WORKDIR /app

# make + Composer inside the container so `make matrix` runs `make check` unchanged.
RUN apt-get update \
    && apt-get install -y --no-install-recommends make \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.11.0 /usr/bin/composer /usr/bin/composer
