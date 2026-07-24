FROM php:8.2-cli
RUN docker-php-ext-install pdo_mysql
# Extension zip (clase ZipArchive): la imagen base NO la trae. Necesaria para
# empaquetar las muestras impresas (Set Basico + Simulacion) en un solo ZIP
# descargable desde el panel (ver src/Pdf/MuestrasImpresasZipBuilder.php), y
# tambien la usa PhpSpreadsheet internamente (un .xlsx es un ZIP).
# docker-php-ext-install la compila desde el .so del sistema: requiere
# libzip-dev en tiempo de build (Debian, imagen base de php:8.2-cli).
# Extension gd: requerida por phpoffice/phpspreadsheet (M4, carga masiva de
# notas de venta) para el lector/escritor de .xlsx; libpng-dev es su
# dependencia de build.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev \
    && docker-php-ext-install zip gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Timezone Chile para TODO proceso PHP (HTTP y CLI). PHP no lee el env TZ para su
# zona por defecto; la toma de date.timezone. Sin esto cae a UTC y las emisiones
# nocturnas (despues de ~20:00 CL) salen con fecha del dia siguiente.
RUN printf "date.timezone=America/Santiago\n" > "$PHP_INI_DIR/conf.d/zz-timezone.ini"

# OpenSSL 3 "legacy" provider: sin esto, openssl_pkcs12_read() falla con un
# "invalid password" enganoso al leer certificados .pfx/.p12 del SII cifrados
# con RC2/3DES (la mayoria de los emitidos), porque OpenSSL 3 desactiva esos
# algoritmos por defecto. Activa AMBOS proveedores (default + legacy) para
# cualquier proceso OpenSSL de la imagen (incluye el CLI de PHP).
COPY docker/openssl-legacy.cnf /etc/ssl/openssl-legacy.cnf
ENV OPENSSL_CONF=/etc/ssl/openssl-legacy.cnf
