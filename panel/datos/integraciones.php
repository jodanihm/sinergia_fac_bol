<?php

declare(strict_types=1);

/**
 * INTEGRACIONES -- dato mantenido a mano, sin base de datos.
 *
 * Este archivo SOLO devuelve un array: sin logica, sin consultas, sin salida.
 *
 * DE DONDE SALIO EL CONTENIDO. No de memoria: se listaron los hosts externos
 * que aparecen en el codigo (grep de https:// sobre src/ e integration/) y se
 * cruzaron con las variables de entorno declaradas en .env.example. Cada
 * entrada nombra el archivo que hace la llamada, asi que si una integracion se
 * borra o se mueve, esto queda mintiendo y hay que corregirlo aca.
 *
 * LA SONDA ES LA PARTE DELICADA. Probar una integracion no puede tener efectos:
 * nada de emitir un documento, mandar un correo ni consumir una semilla del
 * SII. Por eso cada entrada declara QUE clase de prueba admite, y son dos
 * cosas muy distintas que la pantalla NO puede pintar del mismo verde:
 *
 *   'autenticada'  La sonda usa la credencial real contra un endpoint de solo
 *                  lectura. Un OK aqui significa "la credencial sirve". Es lo
 *                  que se quiere, y solo se puede cuando el servicio ofrece un
 *                  endpoint inofensivo (Brevo /v3/account, DeepSeek /models).
 *
 *   'alcance'      La sonda solo comprueba que el host responda. Un OK aqui NO
 *                  dice nada sobre las credenciales: dice que hay DNS, que el
 *                  TLS cierra y que del otro lado hay algo vivo. Es todo lo que
 *                  se puede hacer sin efectos cuando la autenticacion exige un
 *                  certificado de un contribuyente concreto (SII) o cuando
 *                  cada llamada real cuesta plata o cupo (API Gateway).
 *
 * PARA 'alcance' CUALQUIER RESPUESTA HTTP CUENTA COMO ALCANZADO, incluso un 404
 * o un 500. Suena raro y es lo correcto: la pregunta que responde esa sonda es
 * "llego mi paquete y volvio algo", no "el endpoint que elegi existe". Los
 * hosts del SII devuelven 500 en la raiz y 404 en pangal/rahue estando
 * perfectamente sanos; tratar eso como falla seria una alarma permanente.
 *
 * QUE ES CADA CAMPO:
 *   id          clave corta y estable, va en la URL de la sonda
 *   nombre      como se llama, para una persona
 *   para_que    que resuelve en este producto; que se rompe si se cae
 *   donde       el archivo del repo que hace la llamada
 *   host        el dominio, para reconocerlo de un vistazo
 *   credencial  nombre de la variable de entorno, o null si no lleva
 *   sonda       null si no se puede probar sin efectos; si no:
 *                 tipo    'autenticada' | 'alcance'
 *                 url     el endpoint de solo lectura a golpear
 *                 auth    'header:<nombre>' | 'bearer' | null
 *   nota        lo que hay que saber al leer el resultado de la sonda
 *
 * VERIFICADO EL 25-08-2026 contra los seis hosts, desde el contenedor del panel.
 */

return [
    [
        'id'         => 'sii-dte',
        'nombre'     => 'SII - DTE (SOAP)',
        'para_que'   => 'El canal principal: pide la semilla y el token, sube los sobres de facturas y '
            . 'notas, y consulta su estado. Sin esto no se emite ningun documento tributario.',
        'donde'      => 'src/Sii/SiiAutenticador.php, SiiUploader.php, SiiConsultor.php',
        'host'       => 'palena.sii.cl (produccion) / maullin.sii.cl (certificacion)',
        'credencial' => null,
        'sonda'      => [
            'tipo' => 'alcance',
            'url'  => 'https://palena.sii.cl/DTEWS/CrSeed.jws?WSDL',
            'auth' => null,
        ],
        'nota'       => 'NO se puede probar autenticado sin efectos: autenticarse contra el SII consume una '
            . 'semilla y exige el certificado digital de un contribuyente concreto, que es de un cliente y '
            . 'no del sistema. La sonda pide el WSDL publico de la semilla en produccion, que es de solo '
            . 'lectura y no gasta nada.',
    ],
    [
        'id'         => 'sii-boletas',
        'nombre'     => 'SII - Boletas (REST)',
        'para_que'   => 'Canal separado para boletas electronicas: autenticacion y consulta por apicert/api, '
            . 'y envio por pangal/rahue. Es otra API distinta de la de DTE, con su propio ciclo.',
        'donde'      => 'src/Providers/BoletaFacturador.php, src/Sii/BoletaConsultor.php',
        'host'       => 'api.sii.cl (produccion) / apicert.sii.cl (certificacion)',
        'credencial' => null,
        'sonda'      => [
            'tipo' => 'alcance',
            'url'  => 'https://apicert.sii.cl/',
            'auth' => null,
        ],
        'nota'       => 'Mismo limite que el canal de DTE: la autenticacion real necesita el certificado de '
            . 'un contribuyente. La raiz de apicert responde 500 estando sana, asi que lo que se comprueba '
            . 'es que conteste, no con que codigo.',
    ],
    [
        'id'         => 'sii-rcv',
        'nombre'     => 'SII - Registro de Compras y Ventas',
        'para_que'   => 'Descarga el RCV del contribuyente para conciliar lo emitido con lo que el SII tiene '
            . 'registrado.',
        'donde'      => 'src/Sii/RcvConsultor.php',
        'host'       => 'www4.sii.cl',
        'credencial' => null,
        'sonda'      => [
            'tipo' => 'alcance',
            'url'  => 'https://www4.sii.cl/consdcvinternetui/',
            'auth' => null,
        ],
        'nota'       => 'SOLO EXISTE EN PRODUCCION: no hay equivalente en certificacion, y pedirlo con '
            . 'Ambiente::Certificacion es un error declarado en el propio consultor. Autenticar requiere la '
            . 'clave tributaria del contribuyente.',
    ],
    [
        'id'         => 'brevo',
        'nombre'     => 'Brevo',
        'para_que'   => 'Manda todos los correos del sistema: los documentos a los clientes, las ordenes de '
            . 'compra a los proveedores y los avisos. Si se cae, los DTE se emiten igual pero nadie los '
            . 'recibe por correo.',
        'donde'      => 'src/Correo/BrevoMailer.php',
        'host'       => 'api.brevo.com',
        'credencial' => 'BREVO_API_KEY',
        'sonda'      => [
            'tipo' => 'autenticada',
            'url'  => 'https://api.brevo.com/v3/account',
            'auth' => 'header:api-key',
        ],
        'nota'       => 'La sonda pide los datos de la cuenta, que es de solo lectura y NO manda ningun '
            . 'correo ni consume cupo del plan. Un OK aqui si dice que la credencial sirve.',
    ],
    [
        'id'         => 'deepseek',
        'nombre'     => 'DeepSeek',
        'para_que'   => 'Traduce a consultas las preguntas escritas en castellano del chat del panel, y arma '
            . 'facturas dictadas. Si se cae, el resto del panel sigue funcionando: solo deja de responder '
            . 'el chat.',
        'donde'      => 'src/Providers/DeepSeekTraductorPregunta.php, DeepSeekTraductorArmadoFactura.php',
        'host'       => 'api.deepseek.com',
        'credencial' => 'DEEPSEEK_API_KEY',
        'sonda'      => [
            'tipo' => 'autenticada',
            'url'  => 'https://api.deepseek.com/models',
            'auth' => 'bearer',
        ],
        'nota'       => 'La sonda lista los modelos disponibles: no gasta tokens ni ejecuta ninguna '
            . 'consulta contra el modelo.',
    ],
    [
        'id'         => 'apigateway',
        'nombre'     => 'API Gateway',
        'para_que'   => 'Consulta si un RUT esta autorizado como contribuyente y trae sus datos, para no '
            . 'pedirselos a mano a quien da de alta una empresa.',
        'donde'      => 'src/Providers/ApiGatewayContribuyente.php',
        'host'       => 'app.apigateway.cl',
        'credencial' => 'APIGATEWAY_TOKEN',
        'sonda'      => [
            'tipo' => 'alcance',
            'url'  => 'https://app.apigateway.cl/api/v2/',
            'auth' => null,
        ],
        'nota'       => 'NO se sonda autenticado a proposito: este servicio cobra por consulta y no publica '
            . 'un endpoint de estado gratuito. Gastar una consulta de un plan pagado cada vez que alguien '
            . 'aprieta un boton de diagnostico es exactamente lo que no se quiere.',
    ],
    [
        'id'         => 'libredte',
        'nombre'     => 'LibreDTE',
        'para_que'   => 'Proveedor alternativo de emision, detras de FacturadorInterface. Hoy la emision va '
            . 'por el canal directo al SII; esto queda como la segunda implementacion del contrato.',
        'donde'      => 'src/Providers/LibreDteFacturador.php',
        'host'       => 'libredte.cl',
        'credencial' => null,
        'sonda'      => [
            'tipo' => 'alcance',
            'url'  => 'https://libredte.cl/',
            'auth' => null,
        ],
        'nota'       => 'Su token es POR CONTRIBUYENTE y viaja en cada llamada, no hay una credencial del '
            . 'sistema que probar. Ademas se carga una copia local de la libreria desde oracle/, que es '
            . 'otra dependencia distinta de esta API.',
    ],
    [
        'id'         => 'motor',
        'nombre'     => 'Motor (interno)',
        'para_que'   => 'El servicio que emite: el panel le habla por HTTP dentro de la red de docker. No es '
            . 'un tercero, pero si una integracion, y es la que mas veces se rompe en un despliegue.',
        'donde'      => 'docker-compose.vps.yml (MOTOR_URL)',
        'host'       => 'sinergia_motor (red interna, no sale a internet)',
        'credencial' => null,
        'sonda'      => [
            'tipo' => 'autenticada',
            'url'  => '{MOTOR_URL}/health',
            'auth' => null,
        ],
        'nota'       => 'Es la misma comprobacion que hace deploy.sh al final de cada despliegue. Se marca '
            . 'como autenticada porque /health si responde por el servicio de verdad: un OK aqui significa '
            . 'que el motor esta vivo, no solo que el nombre resuelve.',
    ],
];
