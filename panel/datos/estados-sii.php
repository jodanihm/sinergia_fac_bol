<?php

declare(strict_types=1);

/**
 * CATALOGO DE ESTADOS DEL SII, para el usuario -- dato mantenido a mano.
 *
 * Solo devuelve un array, sin logica (misma convencion que changelog.php). Lo
 * consumen el listado del panel de emision (color del badge y detalle al pasar
 * el mouse) y la Guia de estados (partials/_guia-estados-sii.php). Un solo
 * texto por estado: si se corrige aqui, se corrige en los dos lados.
 *
 * LO QUE NO VIVE AQUI: "se puede rehacer" y "fuera de los totales". Esos salen
 * de RehacerDocumento::permite y EstadoContable::esRechazado, que son las
 * reglas reales; este archivo solo pone las palabras.
 *
 * grupos: orden de presentacion. 'tono' elige la paleta (proceso | exito |
 *         error | alerta) e 'icono' es una clave de ICONOS.
 * estados: 'codigo' es el valor tal como lo guarda el sistema (y el que acepta
 *         el filtro Estado). null = documento sin estado.
 *
 * El texto evita tildes a proposito, igual que el resto del panel.
 */
return [
    'grupos' => [
        'proceso' => ['titulo' => 'En proceso', 'sub' => 'El SII todavia esta revisando el envio. Se actualizan solos.', 'tono' => 'proceso', 'icono' => 'reloj'],
        'aceptado' => ['titulo' => 'Aceptados', 'sub' => 'El documento quedo emitido y cuenta como venta.', 'tono' => 'exito', 'icono' => 'check'],
        'rechazado' => ['titulo' => 'Rechazados', 'sub' => 'El documento no quedo emitido. Se pueden rehacer con los mismos datos.', 'tono' => 'error', 'icono' => 'cerrar'],
        'revisar' => ['titulo' => 'Requieren revision', 'sub' => 'Casos especiales: miralos antes de volver a emitir.', 'tono' => 'alerta', 'icono' => 'alerta'],
    ],
    'estados' => [
        ['grupo' => 'proceso', 'codigo' => 'enviado', 'nombre' => 'Enviado al SII',
            'explica' => 'Firmamos el documento y lo enviamos. El SII aun no responde.',
            'hacer' => 'Nada. El estado se actualiza solo, normalmente en minutos.'],
        ['grupo' => 'proceso', 'codigo' => 'REC', 'nombre' => 'Envio recibido',
            'explica' => 'El SII recibio el archivo y lo puso en cola para revisarlo.',
            'hacer' => 'Nada. Espera a que avance.'],
        ['grupo' => 'proceso', 'codigo' => 'SOK', 'nombre' => 'Schema validado',
            'explica' => 'La estructura del XML es correcta. El SII sigue con las demas validaciones.',
            'hacer' => 'Nada. Espera a que avance.'],
        ['grupo' => 'proceso', 'codigo' => 'FOK', 'nombre' => 'Firma validada',
            'explica' => 'La firma electronica del envio es valida. Faltan las validaciones del contenido.',
            'hacer' => 'Nada. Espera a que avance.'],
        ['grupo' => 'proceso', 'codigo' => 'CRT', 'nombre' => 'Caratula correcta',
            'explica' => 'Los datos del envio (emisor, resolucion, fechas) calzan con lo que tiene el SII.',
            'hacer' => 'Nada. Espera a que avance.'],
        ['grupo' => 'proceso', 'codigo' => 'PRD', 'nombre' => 'Envio en proceso',
            'explica' => 'El SII esta revisando los documentos del envio uno por uno.',
            'hacer' => 'Nada. Espera a que avance.'],
        ['grupo' => 'proceso', 'codigo' => '-11', 'nombre' => 'Aun sin resultado',
            'explica' => 'Consultamos al SII y todavia no tiene un veredicto para este envio.',
            'hacer' => 'Nada. Lo volvemos a consultar mas tarde.'],

        ['grupo' => 'aceptado', 'codigo' => 'EPR', 'nombre' => 'Envio procesado',
            'explica' => 'El SII termino de revisar el envio. Es el estado normal de un documento emitido correctamente.',
            'hacer' => 'Nada. Si el SII dejo observaciones dentro del envio, te avisamos por correo.'],
        ['grupo' => 'aceptado', 'codigo' => 'DOK', 'nombre' => 'Documento aceptado',
            'explica' => 'El SII confirmo el documento y sus datos coinciden con los nuestros.',
            'hacer' => 'Nada. Esta todo en orden.'],
        ['grupo' => 'aceptado', 'codigo' => 'RPR', 'nombre' => 'Aceptado con reparos',
            'explica' => 'El documento es valido y la venta existe, pero el SII dejo una observacion.',
            'hacer' => 'Revisa la observacion para corregirla en los proximos documentos.'],

        ['grupo' => 'rechazado', 'codigo' => 'RCT', 'nombre' => 'Error en caratula',
            'explica' => 'Los datos del envio no calzan con el SII: RUT del emisor, numero o fecha de resolucion.',
            'hacer' => 'Revisa los datos de tu empresa y usa "Rehacer con los mismos datos".'],
        ['grupo' => 'rechazado', 'codigo' => 'RCH', 'nombre' => 'Error en la informacion',
            'explica' => 'Algun dato del documento es invalido: receptor, giro, fechas o detalle.',
            'hacer' => 'Corrige el dato y usa "Rehacer con los mismos datos".'],
        ['grupo' => 'rechazado', 'codigo' => 'RCO', 'nombre' => 'Error de consistencia',
            'explica' => 'Los montos no cuadran entre si: neto, IVA, total o las referencias.',
            'hacer' => 'Revisa los montos y usa "Rehacer con los mismos datos".'],
        ['grupo' => 'rechazado', 'codigo' => 'RFR', 'nombre' => 'Error en la firma',
            'explica' => 'La firma no fue valida: certificado vencido o un caracter que el SII no acepta.',
            'hacer' => 'Revisa tu certificado y usa "Rehacer con los mismos datos".'],
        ['grupo' => 'rechazado', 'codigo' => 'RSC', 'nombre' => 'Error de estructura',
            'explica' => 'El XML no cumple el formato del SII (schema).',
            'hacer' => 'Usa "Rehacer con los mismos datos". Si se repite, escribenos.'],
        ['grupo' => 'rechazado', 'codigo' => 'VOF', 'nombre' => 'Archivo no encontrado',
            'explica' => 'El SII no encontro el archivo XML del envio.',
            'hacer' => 'Usa "Rehacer con los mismos datos".'],

        ['grupo' => 'revisar', 'codigo' => 'RPT', 'nombre' => 'Envio repetido',
            'explica' => 'El SII rechazo una copia de un envio que ya tenia. El original probablemente si entro.',
            'hacer' => 'No lo rehagas: duplicarias la venta. Busca el documento original.'],
        ['grupo' => 'revisar', 'codigo' => 'DNK', 'nombre' => 'Datos no coinciden',
            'explica' => 'El SII tiene ese folio, pero con datos distintos a los nuestros.',
            'hacer' => 'Revisalo a mano y escribenos antes de emitir otro.'],
        ['grupo' => 'revisar', 'codigo' => 'ANC', 'nombre' => 'Documento anulado',
            'explica' => 'El documento existio y se anulo con una nota de credito.',
            'hacer' => 'Si hace falta, emite un documento nuevo desde cero.'],
        ['grupo' => 'revisar', 'codigo' => 'sin_trackid', 'nombre' => 'Sin track ID',
            'explica' => 'El envio salio, pero el SII no devolvio un numero de seguimiento para consultarlo.',
            'hacer' => 'Abre la ficha del documento. Si sigue igual, escribenos.'],
        ['grupo' => 'revisar', 'codigo' => 'desconocido', 'nombre' => 'Respuesta no legible',
            'explica' => 'El SII respondio, pero sin un estado que pudieramos leer. La venta se sigue contando.',
            'hacer' => 'Abre la ficha y consulta de nuevo el estado.'],
        ['grupo' => 'revisar', 'codigo' => null, 'nombre' => 'Sin estado',
            'explica' => 'Todavia no tenemos ninguna respuesta registrada para este documento.',
            'hacer' => 'Abre la ficha del documento para revisarlo.'],
    ],
];
