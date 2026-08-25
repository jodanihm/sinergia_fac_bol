<?php

declare(strict_types=1);

/**
 * PENDIENTES E IDEAS -- dato mantenido a mano, sin base de datos.
 *
 * Solo devuelve un array: sin logica, sin consultas, sin salida.
 *
 * CICLO DE VIDA: al concretar o descartar un item SE BORRA DE AQUI y, si se
 * concreto, se registra en el changelog. Esta lista es lo que falta, no un
 * historial -- para eso esta panel/datos/changelog.php. Un pendiente que se
 * queda despues de hecho convierte la lista en ruido, y una lista con ruido
 * deja de mirarse.
 *
 * Forma de cada item:
 *   titulo   una linea
 *   detalle  el por que, y que habria que resolver
 *   tipo     'pendiente' (falta hacerlo) | 'idea' (habria que decidir si vale)
 *   estado   'nuevo' | 'en_pausa' | 'en_curso'
 *
 * La semilla de abajo es lo que las fases 1 a 5 del panel de control dejaron
 * fuera a proposito, con el motivo escrito. Nada de esto es un olvido.
 */

return [
    [
        'titulo'  => 'La cuenta demo hace fallar la consulta de veredictos cada 15 minutos',
        'detalle' => 'Desde el 05-08-2026 a las 12:00, cada corrida de consultar_veredictos_pendientes.php '
            . 'registra tres FALLO con "Fallo descifrado AES-256-GCM" para el RUT 76543210-3, que es '
            . 'DEMO_RUT en scripts/sembrar_demo.php. No es un dato corrupto ni una llave rotada: la siembra '
            . 'de la demo inserta un certificado de RELLENO a proposito, y lo dice en su cabecera -- se '
            . 'puede porque ninguna ruta GET del panel lo descifra, y el unico camino que descifra material '
            . 'criptografico es el de emision, que es POST y esta bloqueado para la demo. Ese razonamiento '
            . 'tiene un hueco: el CRON no es ninguna de las dos cosas. La siembra deja tres documentos en '
            . 'estado "enviado" con track_id (folios 78, 79 y 80), el cron los toma como pendientes de '
            . 'veredicto, intenta descifrar el certificado para autenticarse ante el SII y falla. Va a '
            . 'seguir fallando para siempre, porque nada lo saca de ese estado. Cuesta poco -- que la '
            . 'siembra deje esos tres en un estado terminal (EPR o RCT) como los otros 280, o que el cron '
            . 'salte el RUT de la demo -- y lo caro es lo otro: 48 de las ultimas 60 lineas de esa bitacora '
            . 'son este fallo, asi que un fallo NUEVO y real llega a un log donde ya nadie mira. Se vio al '
            . 'estrenar /admin/tareas, que es justo para lo que se hizo.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => '12 tablas guardan documentos sin poder decir de que empresa son',
        'detalle' => 'Lo dejo a la vista la columna de aislamiento de /admin/base-datos: dte_emitido, '
            . 'dte_caf, dte_certificado, dte_folio, dte_libro, dte_idempotencia y companhia cuelgan de '
            . 'rut_emisor y no tienen cuenta_id ni ninguna clave foranea hacia cuenta. Son las que guardan '
            . 'los documentos tributarios, o sea las que mas caro cuestan si se filtran, y hoy ninguna '
            . 'restriccion de la base impide que una consulta mal escrita mezcle dos contribuyentes. '
            . 'Agregarles cuenta_id es una migracion grande y con backfill; antes hay que decidir si '
            . 'conviene eso o una convencion verificada de otra forma. No es un bug abierto: es un riesgo '
            . 'estructural que ahora se puede mirar.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'Vencimiento de los certificados digitales',
        'detalle' => 'dte_certificado no guarda la vigencia: la fecha vive DENTRO del certificado, en '
            . 'cert_data_cifrado. Por eso la ficha de cuenta no la lista y la portada no tiene la alerta '
            . 'de "certificados proximos a vencer" que si tiene las de folios y correos. Calcularla '
            . 'obligaria a descifrar el certificado de cada cuenta en cada carga de pantalla, que no es '
            . 'el precio correcto. La salida razonable es guardar la fecha de vencimiento en una columna '
            . 'nueva al momento de cargar el certificado (ahi ya esta descifrado) y llenarla hacia atras '
            . 'una sola vez. Requiere migracion.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'Ultimo acceso de cada usuario',
        'detalle' => 'La ficha de cuenta iba a mostrarlo y la tabla usuario no tiene la columna: guarda '
            . 'created_at y activacion_expira, nada sobre el ultimo login. Saber quien no entra hace tres '
            . 'meses sirve para soporte y para detectar cuentas abandonadas. Es una columna nullable mas '
            . 'un UPDATE en handleLoginPost(). Requiere migracion.',
        'tipo'    => 'idea',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'Pagina de APIs e integraciones',
        'detalle' => 'El panel hermano tiene una y quedo fuera de alcance a proposito. Aqui las '
            . 'credenciales de API ya se ven por cuenta en la ficha (solo el prefijo) y el motor se '
            . 'configura por variables de entorno, asi que todavia no esta claro que resolveria una '
            . 'pantalla propia. Decidir si vale antes de construirla.',
        'tipo'    => 'idea',
        'estado'  => 'en_pausa',
    ],
    [
        'titulo'  => 'No hay cuenta de demostracion en la base local',
        'detalle' => 'Ningun usuario tiene demo = 1 de forma permanente. El corte de escritura SI quedo '
            . 'comprobado -- se marco demo = 1 a mano un momento y se confirmo que un POST devuelve la '
            . 'pantalla de modo demostracion y que navegar no crea ninguna credencial --, pero la prueba '
            . 'hay que rearmarla cada vez. Una cuenta demo sembrada de forma estable dejaria esa '
            . 'verificacion disponible sin tocar datos.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'La suite tiene 38 errores y 5 fallos de arrastre',
        'detalle' => 'Son anteriores al panel de control (se comprobo corriendo la suite con y sin los '
            . 'cambios: identica). Dos causas, ninguna del panel: fixtures de SQLite a los que les falta '
            . 'la columna c.dek_envuelta, y openssl_csr_sign fallando dentro del contenedor en '
            . 'RcvConsultorTest. Mientras esten, la suite no sirve como semaforo: hay que comparar contra '
            . 'el baseline a mano, y eso es exactamente lo que hace que una regresion nueva pase '
            . 'inadvertida.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'graphify update se niega a escribir el grafo',
        'detalle' => 'Responde "new graph has 556 nodes but existing graph.json has 1519. Refusing to '
            . 'overwrite" y no se forzo: un --force a ciegas pisaria el grafo bueno con uno incompleto. '
            . 'Parece faltar una re-extraccion completa. Mientras tanto, el grafo que consultan las '
            . 'herramientas no incluye nada de lo construido en el panel de control.',
        'tipo'    => 'pendiente',
        'estado'  => 'nuevo',
    ],
];
