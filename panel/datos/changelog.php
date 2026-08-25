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
        'fecha'   => '2026-08-25',
        'version' => '1.30',
        'titulo'  => 'El menu del panel de control ahora tiene iconos',
        'tag'     => 'frontend',
        'items'   => [
            'Cada opcion del menu lateral lleva un simbolo al lado del nombre, para encontrarla de reojo sin leer la lista entera.',
            'Los dibujos van dentro de la propia pagina: no se descargan de ningun servicio externo, asi que el menu se ve igual aunque no haya internet hacia afuera.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.29',
        'titulo'  => 'Ver de que servicios externos depende el sistema, y probarlos',
        'tag'     => 'devops',
        'items'   => [
            'Pantalla nueva en el panel de control: los ocho servicios de los que depende el sistema (el SII por sus tres canales, Brevo para los correos, DeepSeek para el chat, API Gateway para consultar contribuyentes, LibreDTE y el motor interno), con lo que resuelve cada uno y que se rompe si se cae.',
            'Cada uno tiene un boton que prueba la conexion en el momento y responde en segundos.',
            'La pantalla distingue dos cosas que suelen confundirse: en algunos servicios la prueba usa la clave de verdad y un resultado correcto significa que la clave sirve; en otros solo se puede comprobar que el servicio conteste, y ahi lo dice con todas sus letras en vez de mostrar un visto bueno que no corresponde.',
            'Ninguna prueba tiene consecuencias: no emite documentos, no manda correos, no consume tramites ante el SII ni gasta consultas de los planes que se pagan por uso.',
            'Tambien se ve si cada clave esta configurada, sin mostrarla nunca.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.28',
        'titulo'  => 'Los pendientes pasan a ser un backlog de verdad, y las ideas se van aparte',
        'tag'     => 'arquitectura',
        'items'   => [
            'La pantalla de pendientes ahora es una lista ordenada por prioridad, con contadores arriba (cuanto falta, cuantos urgentes, cuantos en curso) y filtros por area, categoria, prioridad, estado y texto.',
            'Se pincha cualquier fila y se abre la ficha, donde el estado se cambia con un boton: tomarlo, pausarlo, bloquearlo, cerrarlo. Antes eso obligaba a editar un archivo y volver a publicar el sistema, o sea que en la practica nadie lo movia y la lista no reflejaba el trabajo real.',
            'Lo que se cierra ya no se borra: queda con su fecha y quien lo cerro, y sale del listado por defecto. Se puede responder "que se hizo este mes" sin revisar el historial tecnico.',
            'Las ideas tienen pantalla propia. Una idea no es trabajo comprometido sino una pregunta sin responder, y mezclarlas con lo pendiente inflaba la lista con cosas que quiza nunca se hagan.',
            'Cada cambio de estado queda en la auditoria, con quien lo hizo y como estaba antes.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.27',
        'titulo'  => 'Anotado el fallo que la cuenta de demostracion provoca cada 15 minutos',
        'tag'     => 'datos',
        'items'   => [
            'Queda registrado en "Pendientes e ideas" que la cuenta de demostracion deja tres documentos en un estado del que nunca salen, y que eso hace fallar la consulta al SII en cada corrida desde el 5 de agosto.',
            'No hay ningun dato de cliente en riesgo: los documentos y el certificado de esa cuenta son de relleno, puestos ahi para que la demostracion se vea completa.',
            'Lo que si molesta es el ruido: casi todo lo que se registra de esa tarea es este mismo fallo, y eso hace que un problema nuevo pase desapercibido.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.26',
        'titulo'  => 'Saber si una tarea programada corrio bien, sin entrar al servidor',
        'tag'     => 'devops',
        'items'   => [
            'Al pinchar el nombre de una tarea en "Tareas programadas" se abre su bitacora: arriba, en una linea, si viene bien o hay que mirarla; abajo, el registro tal cual lo escribio el servidor, con los fallos destacados en rojo.',
            'El veredicto entiende que las tres tareas no se comportan igual. La de correos solo escribe cuando tiene trabajo, asi que semanas sin una linea son normales y no se marcan como problema; las otras dos escriben en cada corrida, y ahi el silencio si es una alarma.',
            'La pantalla dice lo que sabe y nada mas: la bitacora prueba lo que el programa alcanzo a escribir, no que el servidor lo haya llamado. Para eso queda indicado donde mirar.',
            'Estos registros hasta ahora solo se podian leer entrando al servidor por consola.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.25',
        'titulo'  => 'Ver que tareas corren solas en el servidor y a que hora',
        'tag'     => 'devops',
        'items'   => [
            'El panel de control tiene una pantalla nueva, "Tareas programadas", con los tres trabajos que el servidor ejecuta solo: enviar los correos de los documentos, enviar los correos de las ordenes de compra y preguntarle al SII que paso con lo enviado.',
            'De cada uno se ve cada cuanto corre, dicho en castellano, y las tres proximas veces que le toca, en hora de Chile. Hasta ahora eso solo se sabia entrando al servidor por consola.',
            'La pantalla dice cuando le TOCA a cada tarea, no si la ultima vez resulto bien: es un calendario, no una alarma. Queda escrito en la misma pagina para que nadie la lea como si fuera un monitor.',
            'Cada tarea muestra ademas que supone sobre su propia frecuencia, para que cambiarsela en el servidor no la rompa en silencio.',
            'Solo la ve el equipo interno; ningun cliente llega a esa pantalla.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.24',
        'titulo'  => 'Dar de alta un cliente desde el panel de control',
        'tag'     => 'backend',
        'items'   => [
            'El equipo interno puede crear una cuenta y su usuario propietario en un paso, sin esperar a que el cliente abra un correo. Resuelve el alta por telefono.',
            'La clave se genera sola, al azar, y se muestra una sola vez en pantalla: no queda guardada en ninguna parte y no se puede recuperar. Solo su version cifrada llega a la base.',
            'La primera vez que ese cliente entra, el sistema no lo deja pasar a ninguna pantalla hasta que reemplace esa clave por una que solo el conozca: mientras tanto, alguien mas la conoce.',
            'El alta queda registrada en la auditoria, sin la clave.',
            'El registro publico de siempre no cambia en nada.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.23',
        'titulo'  => 'Las pantallas de acceso ya no delatan que correos estan registrados',
        'tag'     => 'backend',
        'items'   => [
            'Aunque el mensaje de error siempre fue el mismo, el sistema respondia mucho mas rapido cuando el correo no existia que cuando existia con la clave equivocada. Esa diferencia de tiempo permitia recorrer una lista de correos y averiguar cuales son clientes, sin acertar ninguna contrasena.',
            'Ahora los dos casos tardan lo mismo, y tambien el de una cuenta desactivada, que delataba lo mismo.',
            'Vale para las dos pantallas de acceso, y una tercera que se agregue en el futuro lo hereda sola.',
            'Nada mas cambia: los mensajes, las redirecciones y el manejo de sesion son identicos.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.22',
        'titulo'  => 'Puerta de entrada propia para el equipo interno',
        'tag'     => 'backend',
        'items'   => [
            'El panel de control tiene su propia pantalla de acceso, con su aspecto y terminando directo en el tablero interno en vez del panel de una empresa.',
            'Quien no sea del equipo interno recibe exactamente la misma respuesta que si la clave estuviera mal: la pantalla no sirve para averiguar que correos existen en el sistema ni cuales son cuentas de clientes.',
            'La pantalla de acceso de siempre no cambia: quien entra por ahi sigue llegando a su panel igual que antes.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.21',
        'titulo'  => 'Los nombres de cuenta se ven como lo que son: enlaces',
        'tag'     => 'frontend',
        'items'   => [
            'En el listado de cuentas del panel de control, el nombre de cada empresa llevaba a su ficha desde hace rato, pero se veia igual que cualquier otro texto: habia que pasarle el mouse por encima para descubrirlo.',
            'Lo mismo pasaba con los enlaces a la cuenta afectada en la pantalla de auditoria.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.20',
        'titulo'  => 'Las sesiones de solo lectura ya no crean credenciales sin querer',
        'tag'     => 'backend',
        'items'   => [
            'Recorrer el sistema en modo demostracion, o mirar el panel de un cliente como superadmin, podia crear una credencial interna de la empresa como efecto colateral de abrir una pantalla. Ya no.',
            'Tampoco se reemplaza una credencial que este danada: antes, mirar el panel de un cliente con ese problema le revocaba la credencial en uso y creaba otra.',
            'Cuando la credencial falta, las pantallas que dependen de ella lo dicen en vez de fabricarla.',
            'Fuera de esos dos modos no cambia nada: la credencial se sigue creando sola la primera vez que hace falta.',
        ],
    ],
    [
        'fecha'   => '2026-08-22',
        'version' => '1.19',
        'titulo'  => 'El equipo interno puede ver el panel de un cliente, sin poder tocarlo',
        'tag'     => 'arquitectura',
        'items'   => [
            'Desde la ficha de una cuenta se puede abrir el panel de ese cliente y recorrerlo con sus datos reales, para atender un problema viendo exactamente lo mismo que ve quien llama por telefono.',
            'La vista es de solo lectura y no depende de acordarse: cualquier accion que modifique datos queda bloqueada en un unico punto del sistema, antes de llegar a ninguna pantalla.',
            'Un aviso permanente arriba de cada pagina dice de que empresa son los datos que se estan viendo, con un boton para salir.',
            'Entrar y salir quedan registrados en la auditoria a nombre de quien lo hizo: mirar los documentos de un contribuyente deja rastro aunque no se cambie nada.',
        ],
    ],
    [
        'fecha'   => '2026-08-21',
        'version' => '1.18',
        'titulo'  => 'Diagrama de la base de datos, navegable',
        'tag'     => 'datos',
        'items'   => [
            'La pantalla de base de datos ahora tiene una segunda vista: el diagrama completo de las 37 tablas y sus 33 relaciones, con lupa y arrastre.',
            'El dibujo se arma en el servidor a partir de la estructura real, asi que refleja la base tal como esta hoy y no un diagrama que alguien dibujo una vez.',
            'La libreria que lo dibuja se guarda dentro del proyecto y se sirve desde el propio servidor, nunca desde un servicio externo: una pantalla que depende de un tercero es una pantalla que se cae el dia que ese tercero falla.',
            'Solo se carga al abrir el diagrama; quien entra a ver una tabla no paga ese costo.',
        ],
    ],
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
