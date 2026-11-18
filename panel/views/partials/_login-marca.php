<?php
/**
 * Panel de marca de las pantallas sin sesion (login y recuperar contrasena).
 * Decorativo: aria-hidden. Necesita $mascotaSrc (url versionada de la mascota).
 */
?>
    <aside class="lg-marca" aria-hidden="true">
        <div class="lg-marca__aurora">
            <span></span><span></span><span></span>
        </div>
        <div class="lg-marca__malla"></div>

        <div class="lg-marca__contenido">
            <p class="lg-marca__sello"><span class="lg-marca__punto"></span>Sinergia Facturacion</p>
            <h2 class="lg-marca__titulo">Tu facturacion electronica, <span>en orden.</span></h2>
            <p class="lg-marca__bajada">Emite, sigue y envia tus documentos tributarios desde un solo lugar.</p>

            <ul class="lg-marca__puntos">
                <li>
                    <span class="lg-marca__icono"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg></span>
                    Facturas, boletas y notas ante el SII
                </li>
                <li>
                    <span class="lg-marca__icono"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                    El estado de cada documento, a la vista
                </li>
                <li>
                    <span class="lg-marca__icono"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/></svg></span>
                    Envio automatico por correo a tus clientes
                </li>
            </ul>
        </div>

        <img class="lg-marca__mascota" src="<?= htmlspecialchars($mascotaSrc); ?>" width="240" height="240" alt="" decoding="async">
    </aside>
