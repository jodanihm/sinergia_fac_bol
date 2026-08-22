<?php

declare(strict_types=1);

/**
 * FLUJOS DEL PRODUCTO -- dato mantenido a mano, sin base de datos.
 *
 * Solo devuelve un array: sin logica, sin consultas, sin salida.
 *
 * DE DONDE SALE EL CONTENIDO. No de memoria: los pasos y las rutas se
 * reconstruyeron leyendo el router de panel/public/index.php, las estaciones
 * que pinta el dashboard del tenant y el predicado estadoEmisionProduccion().
 * Cada 'donde' es una ruta que existe de verdad; si una ruta se renombra, este
 * archivo queda mintiendo y hay que corregirlo aca.
 *
 * POR QUE ESTA PAGINA EXISTE. "De cero a emitir en produccion" es EL flujo de
 * este producto -- lo que separa a alguien que contrato de alguien que factura
 * -- y hasta ahora no estaba escrito en ninguna parte: vivia repartido entre
 * las estaciones del dashboard, los guards de cada ruta y la cabeza de quien
 * ya lo hizo una vez. Quien atiende el telefono no tiene donde mirarlo.
 *
 * Forma de cada flujo:
 *   id, titulo, resumen
 *   necesitas[]  lo que hay que tener a mano ANTES de empezar
 *   diagrama[]   las cajas del dibujo, en orden
 *   pasos[]      {titulo, detalle, donde}  -- 'donde' es la ruta del panel
 */

return [
    [
        'id'      => 'cero-a-produccion',
        'titulo'  => 'De cero a emitir en produccion',
        'resumen' => 'El camino completo de una empresa nueva: primero certificarse ante el SII en el '
            . 'ambiente de pruebas, despues repetir la carga de credenciales en el ambiente real. Son '
            . 'dos ambientes separados y las credenciales NO se comparten: la empresa, el certificado y '
            . 'los folios se cargan dos veces, una en certificacion y otra en produccion.',
        'necesitas' => [
            'RUT de la empresa y su giro, direccion y comuna',
            'Certificado digital vigente (.p12 o .pfx) y su clave',
            'Clave tributaria del SII, para pedir folios y declarar el avance',
            'El numero y la fecha de la resolucion del SII que autoriza a emitir (llega al final del proceso)',
        ],
        'diagrama' => [
            'Empresa', 'Certificado', 'CAF', 'Certificacion SII (6 etapas)',
            'Declaracion de cumplimiento', 'Autorizacion del SII',
            'Empresa produccion', 'Certificado produccion', 'CAF produccion', 'API key', 'Emitir',
        ],
        'pasos' => [
            [
                'titulo'  => 'Cargar los datos de la empresa',
                'detalle' => 'RUT, razon social, giro, direccion y comuna del emisor, en el ambiente de '
                    . 'certificacion. Los datos se pueden traer del SII por RUT en vez de escribirlos a '
                    . 'mano. Sin esta fila no hay a quien colgarle el certificado ni los folios.',
                'donde'   => '/empresa',
            ],
            [
                'titulo'  => 'Subir el certificado digital',
                'detalle' => 'El .p12 con su clave. Se guarda cifrado con envelope encryption y su '
                    . 'contenido no vuelve a mostrarse nunca, ni al propio dueno. Es lo que firma cada '
                    . 'documento: sin el, no se puede emitir nada.',
                'donde'   => '/certificado',
            ],
            [
                'titulo'  => 'Cargar el primer CAF',
                'detalle' => 'El archivo de folios que entrega el SII, por tipo de documento. En '
                    . 'certificacion los folios son de prueba y no valen tributariamente, pero se '
                    . 'consumen igual: cada emision quema uno y no se recupera.',
                'donde'   => '/caf',
            ],
            [
                'titulo'  => 'Elegir que certificar: factura o boleta',
                'detalle' => 'Son dos procesos distintos ante el SII, con sets de prueba distintos. Una '
                    . 'empresa puede necesitar uno, el otro o los dos.',
                'donde'   => '/certificacion-elegir',
            ],
            [
                'titulo'  => 'Etapa 1 de 6: Set de Prueba',
                'detalle' => 'Emitir el set basico que pide el SII (factura, nota de credito y nota de '
                    . 'debito) mas los libros de ventas y de compras. Esta etapa NO se confirma a mano: '
                    . 'se da por aprobada cuando el SII responde aceptando los tres componentes. Es la '
                    . 'unica de las seis que se calcula de datos reales.',
                'donde'   => '/certificacion/set-pruebas',
            ],
            [
                'titulo'  => 'Etapa 2 de 6: Simulacion',
                'detalle' => 'El caso de simulacion que exige el SII. Se emite desde el panel y despues '
                    . 'se confirma a mano, porque el veredicto lo da el SII por fuera del sistema.',
                'donde'   => '/certificacion/simulacion',
            ],
            [
                'titulo'  => 'Etapa 3 de 6: Intercambio',
                'detalle' => 'Responder los tres XML de intercambio (acuse de recibo, resultado y '
                    . 'recibos de mercaderia). El panel los genera para descargar y subirlos al SII; la '
                    . 'confirmacion es manual.',
                'donde'   => '/certificacion/intercambio',
            ],
            [
                'titulo'  => 'Etapa 4 de 6: Muestras impresas',
                'detalle' => 'Los PDF impresos de los documentos, con su timbre. El panel arma el ZIP '
                    . 'listo para entregar. Confirmacion manual.',
                'donde'   => '/certificacion/muestras-impresas',
            ],
            [
                'titulo'  => 'Etapas 5 y 6 de 6: Declaracion de cumplimiento y Autorizacion',
                'detalle' => 'Se declara el avance en el sitio del SII y se espera la resolucion que '
                    . 'autoriza a emitir. Las dos etapas comparten una sola marca en el sistema '
                    . '(certificacion_confirmada_at), porque entre declarar y ser autorizado no hay nada '
                    . 'que el panel pueda hacer ni observar.',
                'donde'   => '/certificacion-aprobada',
            ],
            [
                'titulo'  => 'Cargar la empresa de produccion',
                'detalle' => 'Los mismos datos del emisor, ahora en el ambiente real, MAS el numero y la '
                    . 'fecha de la resolucion del SII. Esos dos campos son parte de la condicion: sin '
                    . 'resolucion informada el sistema no considera que la empresa pueda emitir, aunque '
                    . 'la fila exista.',
                'donde'   => '/empresa-produccion',
            ],
            [
                'titulo'  => 'Subir el certificado de produccion',
                'detalle' => 'Se vuelve a cargar el certificado, ahora para el ambiente real. No se '
                    . 'hereda del de certificacion a proposito: son ambientes separados y una copia '
                    . 'automatica haria que un error de carga se propagara sin que nadie lo decidiera.',
                'donde'   => '/certificado-produccion',
            ],
            [
                'titulo'  => 'Cargar el CAF de produccion',
                'detalle' => 'Los folios REALES. A partir de aca cada emision consume un folio que vale '
                    . 'tributariamente y que no se puede devolver. Por eso el panel marca en ambar todo '
                    . 'lo de produccion.',
                'donde'   => '/caf-produccion',
            ],
            [
                'titulo'  => 'Generar una API key de produccion (opcional)',
                'detalle' => 'Solo hace falta si la empresa va a emitir desde su propio sistema en vez '
                    . 'de desde el panel. NO es una de las condiciones para emitir: el panel emite sin '
                    . 'ella. La clave se muestra una sola vez, al crearla, y despues solo queda su '
                    . 'prefijo.',
                'donde'   => '/apikeys-produccion',
            ],
            [
                'titulo'  => 'Emitir',
                'detalle' => 'Con empresa (con resolucion), certificado y CAF de produccion, la empresa '
                    . 'ya puede emitir. Esas TRES condiciones, y solo esas, son las que decide '
                    . 'estadoEmisionProduccion() -- el mismo predicado que usan el guard del servidor, el '
                    . 'menu lateral y el panel de control, para que los tres no puedan contradecirse.',
                'donde'   => '/ventas/factura',
            ],
        ],
    ],
    [
        'id'      => 'certificar-boleta',
        'titulo'  => 'Certificar boleta electronica',
        'resumen' => 'Proceso aparte del de factura, con su propio set de prueba y su propia revision '
            . 'del SII. Una empresa que solo factura no lo necesita; una que vende al publico, si. '
            . 'Arranca desde la misma pantalla de eleccion y reusa la empresa, el certificado y el '
            . 'certificado ya cargados para certificacion.',
        'necesitas' => [
            'La empresa, el certificado y un CAF de boleta ya cargados en certificacion',
            'Clave tributaria del SII para el envio del reporte de ventas diarias',
        ],
        'diagrama' => ['Set de boletas', 'Reporte de ventas diarias', 'Revision del SII', 'Cumplimiento'],
        'pasos' => [
            [
                'titulo'  => 'Emitir el set de boletas',
                'detalle' => 'El set de prueba especifico de boleta, distinto del set basico de factura.',
                'donde'   => '/certificacion/boleta/set',
            ],
            [
                'titulo'  => 'Enviar el reporte de ventas diarias (RVD)',
                'detalle' => 'El consolidado del dia que exige el SII para boleta. Se genera y se envia '
                    . 'desde el panel.',
                'donde'   => '/certificacion/boleta/rvd',
            ],
            [
                'titulo'  => 'Esperar la revision y confirmar el cumplimiento',
                'detalle' => 'El SII revisa y responde aprobando o rechazando. El rechazo NO es lo mismo '
                    . 'que "todavia no le toca": el panel lo pinta distinto a proposito, porque un '
                    . 'rechazo pide accion y una etapa no gestionada no.',
                'donde'   => '/certificacion/boleta',
            ],
        ],
    ],
];
