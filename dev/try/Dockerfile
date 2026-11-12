# syntax=docker/dockerfile:1.7
#
# LedgerDirect for Magento 2 — try-it image.
#
# Magento Open Source with the module from this checkout installed the way a merchant installs it
# (Composer, into vendor/), on the XRP Ledger testnet. The shop itself is installed on the first
# start, against the database and search engine of dev/try/compose.yaml; see docker/entrypoint.sh.
#
# Magento comes from Mage-OS's public mirror of Magento Open Source (https://mirror.mage-os.org/),
# which needs no Marketplace access keys. Magento Open Source is licensed under OSL-3.0/AFL-3.0; the
# source and the licence files ship inside this image, under vendor/magento. "Magento" is a trademark
# of Adobe; this image is not an official Magento image.
#
# Build from the repository root:
#   docker build -f dev/try/Dockerfile -t try-magento .

# Base images by tag and digest, written out in the FROM lines: Dependabot (docker) keeps them
# current only there.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer
FROM mlocati/php-extension-installer:2.13.2@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 AS extension-installer

FROM php:8.3.36-cli-bookworm@sha256:232331112d3a7c5b98edeeddc3b85f610172f99352c0635fe6b3e166e47062cb AS base
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
# The PHP extensions Magento requires beyond what the official image builds in, plus gmp for the
# wallet tool's signing (hardcastle/xrpl_php).
RUN install-php-extensions bcmath ftp gd gmp intl pdo_mysql soap sockets xsl zip \
 && apt-get update \
 && apt-get install -y --no-install-recommends curl fonts-dejavu-core \
 && rm -rf /var/lib/apt/lists/*
COPY dev/try/docker/php.ini /usr/local/etc/php/conf.d/zz-ledger-direct-try.ini

FROM base AS build
ARG MAGENTO_VERSION=2.4.8-p5
# The version the module is installed as. Release builds pass the git tag.
ARG LD_VERSION=dev-try
# The mirror occasionally drops an HTTP/2 stream when many downloads run at once (curl error 18):
# fewer parallel downloads, a cache mount that keeps what arrived, and up to three attempts.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MAX_PARALLEL_HTTP=6 \
    COMPOSER_CACHE_DIR=/var/cache/composer
WORKDIR /var/www/html
RUN --mount=type=cache,target=/var/cache/composer \
    composer create-project --no-interaction --no-progress --no-install \
      --repository-url=https://mirror.mage-os.org/ \
      "magento/project-community-edition:${MAGENTO_VERSION}" .
# The module from this checkout, through a path repository that copies it into vendor/ — the same
# place and the same dependency resolution as `composer require` from Packagist.
COPY . /opt/ledger-direct/module
RUN --mount=type=cache,target=/var/cache/composer \
    composer config repositories.ledger-direct "{\"type\":\"path\",\"url\":\"/opt/ledger-direct/module\",\"options\":{\"symlink\":false,\"versions\":{\"hardcastle/ledger-direct-magento2\":\"${LD_VERSION}\"}}}" \
 && composer require --no-interaction --no-update "hardcastle/ledger-direct-magento2:${LD_VERSION}" \
 && for attempt in 1 2 3; do \
      composer install --no-interaction --no-progress --no-dev --optimize-autoloader && break; \
      [ "$attempt" = 3 ] && exit 1; echo "composer install failed (attempt $attempt), retrying"; sleep 10; \
    done \
 && rm -rf /opt/ledger-direct/module

# The shop's testnet wallet tool (hardcastle/xrpl_php), in a vendor/ of its own: it runs as a separate
# process, so its dependencies never meet Magento's.
COPY dev/try/wallet/composer.json /opt/ledger-direct/wallet/composer.json
RUN --mount=type=cache,target=/var/cache/composer \
    composer install --no-interaction --no-progress --no-dev --working-dir=/opt/ledger-direct/wallet

FROM base AS runtime
ARG LD_VERSION=dev-try
ARG MAGENTO_VERSION=2.4.8-p5
LABEL org.opencontainers.image.title="LedgerDirect for Magento 2 — try it" \
      org.opencontainers.image.description="Magento Open Source with the LedgerDirect XRPL payment module, on the XRP Ledger testnet. For trying the module, not for production." \
      org.opencontainers.image.source="https://github.com/ledger-direct/ledger-direct-magento2" \
      org.opencontainers.image.licenses="MIT AND OSL-3.0 AND AFL-3.0" \
      org.opencontainers.image.version="${LD_VERSION}" \
      com.ledger-direct.magento.version="${MAGENTO_VERSION}"
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=build /opt/ledger-direct/wallet /opt/ledger-direct/wallet
COPY dev/try/docker/entrypoint.sh dev/try/docker/install.php dev/try/docker/wallet.php /usr/local/share/ledger-direct/
# The miniatures (catalog.json and pictures; their licence is in dev/try/catalog/NOTICE.md).
COPY dev/try/catalog/catalog.json dev/try/catalog/NOTICE.md /usr/local/share/ledger-direct/catalog/
COPY dev/try/catalog/images /usr/local/share/ledger-direct/catalog/images
# What identifies this build to an existing shop: the resolved packages. When it differs from the one
# the shop was set up with (an updated image on existing volumes), the entrypoint runs setup:upgrade.
# The same for the catalogue: a new one is imported into an existing shop on its next start.
RUN chmod 0755 /usr/local/share/ledger-direct/entrypoint.sh \
 && sha256sum /var/www/html/composer.lock | cut -c1-16 > /usr/local/share/ledger-direct/build-id \
 && (cd /usr/local/share/ledger-direct/catalog && cat catalog.json images/* | sha256sum | cut -c1-16) \
      > /usr/local/share/ledger-direct/catalog-id
USER www-data
ENV LD_PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=3 \
  CMD curl -fs -o /dev/null "http://127.0.0.1:${LD_PORT}/" || exit 1
ENTRYPOINT ["/usr/local/share/ledger-direct/entrypoint.sh"]
