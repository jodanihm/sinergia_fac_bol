# Mermaid vendorizado

    mermaid 10.9.1  (UMD)
    origen   https://unpkg.com/mermaid@10.9.1/dist/mermaid.min.js
    sha256   61b335a46df05a7ce1c98378f60e5f3e77a7fb608a1056997e8a649304a936d6
    bajado   2026-08-21

## Por que esta el archivo aca y no un <script src> a un CDN

El panel va detras de Cloudflare Tunnel y hasta esta pagina no cargaba **ni un
solo archivo JS externo**. Una dependencia de CDN seria la primera, y con ella
una pantalla que se rompe el dia que el CDN falla, cambia una URL o bloquea la
region -- por un diagrama que es una comodidad, no una funcion critica.
Servirlo local cuesta 3,3 MB en el repositorio y no cuesta ninguna
disponibilidad.

## Version fija, no un rango

10.9.1 exacta. Es la ultima linea que publica un bundle **UMD**, que se carga
con un `<script src>` corriente y define `window.mermaid`. Las versiones 11.x
publican solo ESM y obligarian a modulos o a un empaquetador: este proyecto no
tiene build ni dependencias de npm, y agregarlos por un diagrama seria
desproporcionado.

## Como comprobar que el archivo no cambio

    sha256sum panel/public/js/mermaid.min.js

Tiene que dar el sha256 de arriba. Si no da, alguien lo reemplazo: revisar por
que antes de desplegar.

## Como actualizarlo

Bajar la nueva version, actualizar el sha256 y la fecha de este archivo, y
**probar el diagrama en el navegador** -- mermaid cambia de API entre menores
y ningun test de PHP se entera. Solo se sirve en /admin/base-datos?vista=diagrama,
asi que el radio de un problema es esa pantalla.
