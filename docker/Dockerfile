# M+ 3D: PHP-FPM med OpenSCAD (Manifold).
#
# Bildet inneholder bare programvaren. Koden monteres inn fra git-klonen på serveren
# (se docker-compose.yml), slik at en oppdatering er «git pull» + ny utrulling.
#
# Bygg (fra prosjektmappen):  docker build -t mplus3d/php-fpm:1 docker
#
# OpenSCAD hentes som AppImage fra files.openscad.org og kontrolleres mot SHA256.
# Ny versjon: endre OPENSCAD_VERSION og OPENSCAD_SHA256 (fra .sha256-fila ved siden
# av AppImage-fila), og øk taggen på bildet i docker-compose.yml.

FROM php:8.2-fpm-bookworm

ARG OPENSCAD_VERSION=2026.11.10
ARG OPENSCAD_SHA256=05055b4d9c07489afe6c2e33aedb69fd8999ba8c743ed8ab29ea264989915e33

# PHP-utvidelser: gd (kreves av FPDF-pakken) og zip (ZIP-nedlasting av STL-filer).
# Bibliotekene etter dem trenger OpenSCAD for å starte, selv uten skjerm.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates curl \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
        libgl1 libegl1 libglu1-mesa libopengl0 libfontconfig1 libx11-6 libxext6 libxrender1 \
        libxi6 libxkbcommon0 libdbus-1-3 libglib2.0-0 libharfbuzz0b fonts-dejavu-core \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip \
 && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    cd /opt; \
    curl -fsSL -o openscad.AppImage "https://files.openscad.org/snapshots/OpenSCAD-${OPENSCAD_VERSION}-x86_64.AppImage"; \
    echo "${OPENSCAD_SHA256}  openscad.AppImage" | sha256sum -c -; \
    chmod +x openscad.AppImage; \
    ./openscad.AppImage --appimage-extract >/dev/null; \
    mv squashfs-root openscad; \
    # Utpakkingen gir mapper som bare root kan lese. PHP kjører som www-data.
    chmod -R a+rX openscad; \
    rm openscad.AppImage; \
    ln -s /opt/openscad/AppRun /usr/local/bin/openscad

# Composer, så avhengighetene kan installeres med samme PHP som kjører appen.
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-mplus3d.ini
COPY php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-mplus3d.conf

ENV OPENSCAD_BIN=/usr/local/bin/openscad \
    QT_QPA_PLATFORM=offscreen

WORKDIR /var/www/mplus3d
