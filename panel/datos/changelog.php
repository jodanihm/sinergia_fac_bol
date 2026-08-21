<?php

declare(strict_types=1);

/**
 * CHANGELOG DEL PRODUCTO -- dato mantenido a mano, sin base de datos.
 *
 * Este archivo SOLO devuelve un array: sin logica, sin consultas, sin salida.
 * Se carga con require desde el handler que lo necesita. Asi el contenido
 * queda aislado de como se muestra, y el dia que convenga moverlo a una tabla
 * o a un JSON no hay que tocar ninguna vista.
 *
 * POR QUE A MANO Y NO DERIVADO DEL git log. Un mensaje de commit le habla a
 * quien programa ("extraer el catalogo a un archivo compartido"); una entrada
 * de changelog le habla a quien vende, soporta o usa el sistema ("el panel
 * ahora avisa cuando quedan pocos folios"). Son dos textos distintos y el
 * segundo no se deduce del primero. La semilla de abajo si salio del historial
 * real (git log --date=short), agrupando los 123 commits del 23-07-2026 al
 * 21-08-2026 en entradas con sentido para quien no programa.
 *
 * CONVENCION, heredada del admin-web de Brewer Manager: por cada cambio que se
 * haga en el proyecto, una entrada nueva ARRIBA, subiendo la version. Los items
 * dicen QUE cambio para el usuario y POR QUE, no que archivo se toco.
 *
 * Forma de cada entrada:
 *   fecha   'aaaa-mm-dd'
 *   version 'N.NN' -- lo mas nuevo arriba
 *   titulo  una linea
 *   tag     arquitectura | backend | frontend | datos | devops
 *   items   lista de frases, en lenguaje de usuario
 */

return [
    [
        'fecha'   => '2026-08-21',
        'version' => '1.17',
        'titulo'  => 'Documentacion viva del producto: flujos, documentos, pendientes y changelog',
        'tag'     => 'frontend',
        'items'   => [
            'Nueva pagina de flujos con el camino completo de una empresa nueva, "de cero a emitir en produccion", paso a paso y con la pantalla exacta donde se hace cada cosa. Ese recorrido no estaba escrito en ninguna parte: vivia repartido entre el sistema y la cabeza de quien ya lo habia hecho una vez.',
            'Catalogo de todos los documentos que el sistema imprime, con quien los recibe: no es lo mismo un informe interno que una factura que va al SII.',
            'Lista de pendientes e ideas, donde cada cosa que quedo fuera dice por que quedo fuera.',
            'El changelog completo, con las 17 versiones del producto.',
            'Las cuatro paginas se mantienen en archivos de texto, sin base de datos, y hay comprobaciones automaticas de que cada pantalla que prometen exista de verdad: una documentacion que miente es peor que no tenerla, porque se le cree.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.16',
        'titulo'  => 'Roles y permisos, explicados y auditados en una pantalla',
        'tag'     => 'arquitectura',
        'items'   => [
            'Nueva pantalla que explica como se decide si alguien puede entrar a cada parte del sistema, con el recorrido completo dibujado.',
            'Muestra que habilita de verdad cada permiso: cuantas pantallas abre y cuales, contadas sobre el codigo y no sobre una lista aparte.',
            'Muestra los roles que cada empresa configuro, con una matriz de que puede hacer cada uno, y aclara que los duenos de cuenta no estan limitados por esos permisos.',
            'Revisa que ninguna pantalla del sistema haya quedado sin declarar quien puede entrar. Hoy estan las 155 cubiertas.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.15',
        'titulo'  => 'Explorador de la base de datos, con el aislamiento entre empresas a la vista',
        'tag'     => 'datos',
        'items'   => [
            'Nueva pantalla que muestra la estructura completa de la base: tablas, columnas, tipos, claves e indices, leidos del propio motor.',
            'Cada tabla dice como se separa una empresa de otra: si tiene el identificador de cuenta a la vista, si hay que seguir un camino de relaciones para encontrarlo, o si no existe ese vinculo. El camino se calcula solo, asi que una tabla nueva aparece clasificada sin que nadie la agregue a mano.',
            'Quedaron a la vista 12 tablas que guardan documentos tributarios y no tienen relacion directa con la empresa dueña: es donde una consulta mal escrita podria mezclar datos de dos clientes.',
            'La pantalla tambien informa que migraciones estan aplicadas, usando exactamente el mismo catalogo que revisa el despliegue, para que las dos no puedan decir cosas distintas.',
            'No se puede ejecutar ninguna consulta desde esta pantalla: solo lee la estructura.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.14',
        'titulo'  => 'La auditoria dice que cambio, no solo que se toco',
        'tag'     => 'frontend',
        'items'   => [
            'Cada accion administrativa ahora muestra en una linea que campo cambio y de que valor a cual, en vez de dos bloques de datos que habia que comparar a ojo.',
            'El registro completo de antes y despues sigue estando, ahora plegado: se abre cuando hace falta la prueba.',
            'Se puede filtrar por accion, por quien la hizo y por rango de fechas, y el rango incluye el dia completo en los dos extremos.',
            'El listado se pagina de a 50, porque este registro no se borra nunca y solo crece.',
            'Desde cada accion se llega a la ficha de la cuenta afectada.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.13',
        'titulo'  => 'Buscador de cuentas y ficha completa de cada cliente',
        'tag'     => 'frontend',
        'items'   => [
            'El listado de cuentas ahora se puede buscar por nombre, por email o por RUT del emisor, y filtrar por estado.',
            'La busqueda queda en la direccion de la pagina, asi que se puede guardar o compartir el resultado.',
            'Al hacer clic en una cuenta se abre su ficha: usuarios y sus roles, empresas emisoras con su avance de certificacion, certificados, folios disponibles, credenciales de API, documentos emitidos por mes y las acciones administrativas que se hicieron sobre ella.',
            'La ficha es de solo consulta y nunca muestra claves, certificados ni credenciales: de las credenciales de API solo se ve el prefijo.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.12',
        'titulo'  => 'Panel de control interno para el equipo de Sinergia',
        'tag'     => 'arquitectura',
        'items'   => [
            'Nueva area /admin con pantalla propia, separada del panel que usan los clientes: tema oscuro para que nunca se confunda una cosa con la otra.',
            'Pantalla de inicio con las cifras de la plataforma: cuantas cuentas hay, cuantas estan activas, cuantas ya pueden emitir de verdad y cuantos documentos se emitieron en el ultimo mes.',
            'Aviso automatico cuando una empresa esta por quedarse sin folios o tiene correos que no se pudieron enviar.',
            'Las pantallas de cuentas y de auditoria que ya existian pasaron al diseno nuevo, sin cambiar en nada lo que hacen.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.11',
        'titulo'  => 'Roles y permisos dentro de cada empresa',
        'tag'     => 'arquitectura',
        'items'   => [
            'El dueno de una cuenta ahora puede crear roles y decidir que ve y que puede hacer cada persona de su equipo.',
            'Los permisos se aplican en un unico punto del sistema, asi que una pantalla nueva nace bloqueada hasta que alguien decida quien puede entrar.',
            'Administrar roles quedo reservado al dueno: si fuera un permiso mas, cualquiera podria ampliarse a si mismo.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.10',
        'titulo'  => 'Boleta electronica para venta real',
        'tag'     => 'backend',
        'items'   => [
            'Ya se pueden emitir boletas electronicas en produccion, no solo en la certificacion ante el SII.',
            'Corregida la certificacion de boleta, que el SII rechazaba por dos datos del formato.',
        ],
    ],
    [
        'fecha'   => '2026-08-19',
        'version' => '1.09',
        'titulo'  => 'Pagina publica de presentacion',
        'tag'     => 'frontend',
        'items'   => [
            'La direccion principal del sitio ya no lleva directo al formulario de acceso: ahora muestra una pagina que explica el producto.',
            'Quien ya tiene sesion iniciada sigue entrando derecho a su panel.',
        ],
    ],
    [
        'fecha'   => '2026-08-13',
        'version' => '1.08',
        'titulo'  => 'Asistente IA: armar facturas conversando',
        'tag'     => 'backend',
        'items'   => [
            'Ademas de responder preguntas sobre las ventas, el asistente ahora arma facturas: una sola o varias a la vez, describiendolas en lenguaje normal.',
            'Reconoce al cliente por nombre o por RUT y avisa cuando le falta un dato en vez de quedarse en blanco.',
            'Historial de preguntas y limite diario configurable por cuenta.',
        ],
    ],
    [
        'fecha'   => '2026-08-11',
        'version' => '1.07',
        'titulo'  => 'Ordenes de compra y maestro de proveedores',
        'tag'     => 'backend',
        'items'   => [
            'Nuevo modulo para emitir ordenes de compra, con su PDF y su envio por correo.',
            'Maestro de proveedores, equivalente al de clientes.',
        ],
    ],
    [
        'fecha'   => '2026-08-10',
        'version' => '1.06',
        'titulo'  => 'Cotizaciones',
        'tag'     => 'backend',
        'items'   => [
            'Crear, listar, editar y ver cotizaciones, con su PDF.',
            'Una cotizacion se puede convertir en factura, descontando lo ya facturado linea por linea.',
        ],
    ],
    [
        'fecha'   => '2026-08-08',
        'version' => '1.05',
        'titulo'  => 'Modo demostracion, logo propio y control de rechazos del SII',
        'tag'     => 'backend',
        'items'   => [
            'Cuenta de demostracion de solo lectura: se puede recorrer el sistema entero sin que ninguna accion altere datos ni llegue al SII.',
            'Cada empresa puede cargar su logo y sale impreso en los documentos.',
            'El panel avisa cuando el SII acepto un envio pero rechazo documentos dentro de el, algo que antes pasaba inadvertido.',
            'Consulta al SII del estado de autorizacion de un contribuyente por RUT.',
        ],
    ],
    [
        'fecha'   => '2026-08-04',
        'version' => '1.04',
        'titulo'  => 'Impuestos adicionales y veredicto automatico del SII',
        'tag'     => 'backend',
        'items'   => [
            'Los documentos admiten impuestos adicionales y se dibujan en el PDF.',
            'El sistema consulta solo el veredicto del SII y avisa los rechazos, en vez de esperar a que alguien lo revise.',
            'Los documentos rechazados dejaron de sumar en los totales.',
        ],
    ],
    [
        'fecha'   => '2026-08-01',
        'version' => '1.03',
        'titulo'  => 'Envio automatico de los documentos por correo',
        'tag'     => 'backend',
        'items'   => [
            'Al facturar, el documento se encola y se envia por correo al receptor sin intervencion.',
            'Pantalla de la cola de correos, con filtros, conteos y reintento masivo.',
            'Verificador de solo lectura del estado de la base, para saber antes de un despliegue que falta aplicar.',
        ],
    ],
    [
        'fecha'   => '2026-07-29',
        'version' => '1.02',
        'titulo'  => 'Rediseno del panel y dashboard de gestion',
        'tag'     => 'frontend',
        'items'   => [
            'Panel rediseniado completo: menu lateral con iconos, tarjetas de indicadores y tablas que ya no se desbordan en el telefono.',
            'Dashboard de gestion para las empresas que ya estan emitiendo en produccion.',
            'Dos caminos de puesta en marcha: certificar ante el SII, o entrar directo a produccion si la empresa ya esta autorizada.',
            'Modulo de informes en PDF y Excel.',
        ],
    ],
    [
        'fecha'   => '2026-07-24',
        'version' => '1.01',
        'titulo'  => 'Emision, carga masiva y administracion de usuarios',
        'tag'     => 'backend',
        'items'   => [
            'Emision de factura, nota de credito y nota de debito una por una.',
            'Emision masiva a partir de un archivo Excel.',
            'Maestros de clientes y productos.',
            'Cada empresa puede invitar usuarios a su cuenta y revisar el registro de lo que se hizo.',
        ],
    ],
    [
        'fecha'   => '2026-07-23',
        'version' => '1.00',
        'titulo'  => 'Primera version: motor de documentos y panel de autoservicio',
        'tag'     => 'arquitectura',
        'items'   => [
            'Motor de documentos tributarios electronicos capaz de atender a varias empresas sobre la misma instalacion.',
            'Panel donde cada empresa carga su certificado digital y sus folios, y sigue su proceso de certificacion ante el SII.',
        ],
    ],
];
