<?php

declare(strict_types=1);

/**
 * CATALOGO DE DOCUMENTOS IMPRIMIBLES -- dato mantenido a mano, sin base de datos.
 *
 * Solo devuelve un array: sin logica, sin consultas, sin salida.
 *
 * AQUI LA MAYORIA NO SON PDF INTERNOS, SON DTE. Un documento tributario
 * electronico no es un informe que sacamos nosotros: es un archivo con validez
 * legal que recibe un tercero -- el receptor y el SII -- y que no se puede
 * corregir reimprimiendolo. Por eso cada entrada dice PARA QUIEN es: el
 * destinatario cambia por completo lo que significa un error en el documento.
 *
 * El estado 'listo' de los DTE esta comprobado contra TIPOS_PERMITIDOS_PDF en
 * public/index.php (el motor), que hoy declara [33, 34, 61, 56, 39]. Un tipo
 * que no este ahi no tiene PDF por mas que el panel lo emita.
 */

return [
    // --- DTE: documentos con validez tributaria -----------------------------
    [
        'nombre'    => 'Factura electronica (33)',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/factura',
        'para'      => 'El receptor y el SII',
        'estado'    => 'listo',
        'prioridad' => 'alta',
        'nota'      => 'El documento central del producto. PDF con timbre electronico y copia CEDIBLE. '
            . 'El pie se pagina solo cuando el detalle es largo, para que el timbre nunca quede partido.',
    ],
    [
        'nombre'    => 'Factura exenta (34)',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/factura-exenta',
        'para'      => 'El receptor y el SII',
        'estado'    => 'listo',
        'prioridad' => 'alta',
        'nota'      => 'Mismo formato que la 33, sin IVA. En la carga masiva se marca al subir el archivo.',
    ],
    [
        'nombre'    => 'Boleta electronica (39)',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/boleta',
        'para'      => 'El consumidor final y el SII',
        'estado'    => 'listo',
        'prioridad' => 'alta',
        'nota'      => 'Tiene su propio proceso de certificacion, aparte del de factura, y exige el '
            . 'reporte de ventas diarias.',
    ],
    [
        'nombre'    => 'Nota de credito (61)',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/nota-credito',
        'para'      => 'El receptor y el SII',
        'estado'    => 'listo',
        'prioridad' => 'alta',
        'nota'      => 'Corrige o anula un documento anterior. Exige elegir explicitamente el codigo de '
            . 'referencia: anula, corrige texto o corrige montos, y no son intercambiables.',
    ],
    [
        'nombre'    => 'Nota de debito (56)',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/nota-debito',
        'para'      => 'El receptor y el SII',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'El inverso de la nota de credito: aumenta el monto de un documento anterior.',
    ],
    [
        'nombre'    => 'XML del DTE emitido',
        'grupo'     => 'Documentos tributarios',
        'desde'     => '/ventas/panel-emision/{tipo}/{folio}/xml',
        'para'      => 'El receptor, para su propio sistema',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'El documento firmado tal cual se envio al SII. Es el original: el PDF es una '
            . 'representacion impresa de esto, no al reves.',
    ],

    // --- Documentos comerciales, sin validez tributaria ---------------------
    [
        'nombre'    => 'Cotizacion (PDF)',
        'grupo'     => 'Comerciales',
        'desde'     => '/ventas/cotizaciones/{id}/pdf',
        'para'      => 'El cliente, antes de comprar',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'No es un DTE y no se envia al SII. Se puede convertir en factura, descontando '
            . 'el saldo linea por linea.',
    ],
    [
        'nombre'    => 'Orden de compra (PDF)',
        'grupo'     => 'Comerciales',
        'desde'     => '/compras/ordenes/{id}/pdf',
        'para'      => 'El proveedor',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'Tampoco es un DTE. Se puede enviar por correo desde el detalle de la orden.',
    ],

    // --- Informes internos --------------------------------------------------
    [
        'nombre'    => 'Informes en PDF y Excel (6 informes)',
        'grupo'     => 'Informes',
        'desde'     => '/informes/{informe}/pdf  y  /informes/{informe}/excel',
        'para'      => 'La propia empresa',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'Facturacion por tipo de documento, ventas por dia, clientes por facturacion, '
            . 'documentos por estado del SII, detalle documento a documento y estado de folios. Los '
            . 'cinco primeros por periodo; el de folios es una foto del momento.',
    ],
    [
        'nombre'    => 'Plantilla de carga masiva (Excel)',
        'grupo'     => 'Informes',
        'desde'     => '/ventas/carga-masiva/plantilla',
        'para'      => 'La propia empresa, para llenarla y devolverla',
        'estado'    => 'listo',
        'prioridad' => 'media',
        'nota'      => 'Trae las columnas con el formato ya aplicado, para que las fechas no lleguen '
            . 'como texto ambiguo.',
    ],

    // --- Certificacion ------------------------------------------------------
    [
        'nombre'    => 'Muestras impresas (ZIP de PDF)',
        'grupo'     => 'Certificacion',
        'desde'     => '/certificacion/muestras-impresas.zip',
        'para'      => 'El SII, en la etapa 4 de la certificacion',
        'estado'    => 'listo',
        'prioridad' => 'baja',
        'nota'      => 'Se genera y se descarga en el mismo POST, sin guardarse: la generacion es local '
            . 'e idempotente y no tiene efectos de red.',
    ],
    [
        'nombre'    => 'XML de intercambio (acuse, resultado, recibos)',
        'grupo'     => 'Certificacion',
        'desde'     => '/certificacion/intercambio/{tipo}.xml',
        'para'      => 'El SII, en la etapa 3 de la certificacion',
        'estado'    => 'listo',
        'prioridad' => 'baja',
        'nota'      => 'Los tres XML de respuesta que exige la etapa de intercambio.',
    ],
];
