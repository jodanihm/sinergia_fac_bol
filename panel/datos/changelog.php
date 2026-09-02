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
        'fecha'   => '2026-09-02',
        'version' => '1.51',
        'titulo'  => 'El correo de una factura puede llevar un boton para pagarla en linea',
        'tag'     => 'backend',
        'items'   => [
            'Funcion nueva: el correo con el que se manda una factura puede incluir un boton para pagarla, por el monto exacto y contra la cuenta de cobro de la propia empresa. El dinero va a la empresa, no a nosotros.',
            'Se activa empresa por empresa y arranca APAGADO: mientras nadie lo configure, todos los correos salen exactamente igual que hoy. Ademas se puede dejar fuera a clientes concretos, para los que pagan por transferencia o tienen convenio.',
            'Solo va en factura y factura exenta. En una nota de credito no aparece nunca: esa DEVUELVE dinero, y un boton de pagar ahi haria que alguien pagara de mas.',
            'Si la pasarela de pago no responde, el correo ESPERA en vez de salir sin el boton, y se reintenta solo con esperas cada vez mas largas. Si el atasco pasa de 6 horas queda avisado, y desde Ventas > Correos se puede soltar una factura concreta para que salga sin link. Nunca se pierde un correo en silencio.',
            'A quien ya pago no se le vuelve a ofrecer pagar: la pasarela avisa del pago y el sistema lo anota. Ese aviso se comprueba de verdad -- se le pregunta a la pasarela por el estado real -- porque avisa igual cuando el pago se rechaza.',
            'Se configura en Configuracion > Cobro en linea: ahi van las llaves de Flow y el interruptor. La llave secreta se guarda cifrada y no se vuelve a mostrar nunca; para cambiarla se escribe una nueva, y dejarla en blanco significa "no la toques".',
            'No se puede activar sin las llaves cargadas. Encenderlo sin ellas dejaria todos tus correos esperando un link que no llegaria, asi que el sistema no te deja hacerlo.',
            'En la ficha de cada cliente hay una casilla para dejarlo fuera, y en Ventas > Correos una columna nueva que dice si cada factura salio con link, sin el, o esta esperando -- con el boton para soltarla.',
            'Requiere aplicar las migraciones 050, 051 y 052.',
        ],
    ],
    [
        'fecha'   => '2026-09-02',
        'version' => '1.50',
        'titulo'  => 'El RUT se limpia antes de emitir, no despues de que el SII rechace',
        'tag'     => 'backend',
        'items'   => [
            'Escribir el RUT del receptor con puntos -- que es como se escribe un RUT en Chile -- hacia que el SII rechazara el documento por formato. El folio se gastaba igual: el SII no los devuelve. Asi se perdio la nota de credito folio 5.',
            'El sistema si validaba el RUT, pero contestaba la pregunta equivocada: comprobaba que el numero EXISTIERA (digito verificador) quitando los puntos para la cuenta, y despues enviaba el RUT tal como venia, con los puntos. La pregunta que faltaba era si el SII iba a poder leerlo.',
            'Ahora el RUT se deja escrito como corresponde en el punto por el que pasan TODOS los documentos, sea una factura, una boleta, una nota de credito o una linea de un libro. Ya no depende de que cada formulario se acuerde de limpiarlo.',
            'Y si el RUT directamente no existe, el aviso sale en el propio campo del formulario ANTES de emitir nada, en vez de llegar como un error del motor cuando el documento ya se intento.',
            'Esto afectaba a toda la emision de a uno, no solo a las notas de credito. La carga masiva no lo sufria porque sus RUT ya venian limpios.',
            'La nota de credito folio 5 sigue rechazada y hay que volver a emitirla: un documento rechazado por el SII no existe para el SII.',
        ],
    ],
    [
        'fecha'   => '2026-09-02',
        'version' => '1.49',
        'titulo'  => 'Ya se puede comprobar la emision de notas de credito sin gastar un folio',
        'tag'     => 'devops',
        'items'   => [
            'Comprobar si la emision funciona costaba un folio: habia que emitir de verdad. Con dos folios de nota de credito disponibles, probar dos veces dejaba a la empresa sin poder emitir ninguna.',
            'Ahora hay una comprobacion que recorre TODO el camino -- lee el CAF, revisa el certificado, arma la nota de credito, la timbra, la firma y la valida contra el esquema oficial del SII -- y se detiene justo antes de enviarla, que es lo unico que gasta el folio. Al terminar vuelve a mirar el contador y avisa si se movio.',
            'Ademas revisa que dijo el SII de las notas de credito YA enviadas, y valida el archivo que de verdad se le mando. Esa parte es la que importa: comprobar solo la maquinaria puede dar todo verde mientras un documento real se rechaza.',
            'Con esto quedo a la vista que la nota de credito folio 5 fue RECHAZADA por el SII por error de esquema, y por que. El folio se gasto igual: el SII no los devuelve.',
        ],
    ],
    [
        'fecha'   => '2026-09-02',
        'version' => '1.48',
        'titulo'  => 'Cuando faltan folios, el sistema lo dice con esas palabras',
        'tag'     => 'backend',
        'items'   => [
            'Emitir un documento de un tipo sin folios disponibles mostraba "Error del motor de emision. NO se emitio; intenta nuevamente". Ni decia cual era el problema, ni donde se arregla, y encima mandaba a reintentar algo que no podia funcionar: sin CAF cargado no hay folio que asignar, por muchas veces que se apriete el boton.',
            'Ahora el mensaje nombra el tipo que falta -- "No quedan folios de Factura (33)" -- y manda derecho a Configuracion > Folios y CAF, con el detalle por tipo en Informes > Estado de folios.',
            'Ademas el intento fallido bloqueaba el siguiente durante 5 minutos: quien hacia caso al mensaje y reintentaba recibia otro error igualmente mudo. La comprobacion ahora ocurre ANTES de arrancar la emision, asi que no queda nada bloqueado y el reintento entra limpio en cuanto se carga el CAF.',
            'Es el mismo criterio que la facturacion masiva ya aplicaba desde antes: contar los folios antes de tocar nada. La emision de a uno decia seguirlo y no lo hacia; ahora las dos vias responden igual ante el mismo hecho.',
            'Vale para factura, factura exenta, boleta y nota de credito, y tambien para quien llame al motor desde un script propio.',
        ],
    ],
    [
        'fecha'   => '2026-09-02',
        'version' => '1.47',
        'titulo'  => 'La carga masiva ya no se cae con archivos que traen formato de mas',
        'tag'     => 'backend',
        'items'   => [
            'Un Excel podia hacer caer la carga masiva de dos maneras, las dos sin explicar nada: un error tecnico en pantalla ("Allowed memory size exhausted"), o directamente la pagina de error de Cloudflare, como si el sistema estuviera caido.',
            'La causa no estaba en los datos sino en el FORMATO. Pintar las filas enteras -- un fondo, un borde -- es un clic en Excel, y deja el archivo lleno de celdas vacias pero guardadas, mucho mas alla de las columnas de la plantilla. Al abrirlo habia que cargarlas todas igual, y el proceso se quedaba sin memoria a mitad de camino.',
            'Ahora el sistema mide el archivo ANTES de abrirlo. Si no va a poder con el, lo dice al tiro y con instrucciones: que seleccione las filas y columnas sobrantes, las borre y guarde de nuevo. Eso arregla el archivo de verdad, en vez de dejar a la persona mirando un error.',
            'Un archivo con mas filas de las permitidas tambien se rechaza al instante, con su mensaje de siempre. Antes el sistema se pasaba casi un minuto cargandolo para recien entonces decir que no.',
            'Y un resto de texto olvidado a la derecha de la ultima columna ya no arruina el archivo completo. Antes corria el borde de todo el Excel y la carga se rechazaba entera por "los encabezados no coinciden", por algo que el usuario ni siquiera veia en pantalla.',
            'Los archivos que ya funcionaban se cargan exactamente igual: no cambia nada de como se llena la plantilla ni de como se leen las fechas, los montos o las cantidades.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.46',
        'titulo'  => 'Todas las madrugadas queda un respaldo por cliente, en el servidor y en Nextcloud',
        'tag'     => 'devops',
        'items'   => [
            'Nueva tarea programada, a las 03:40: arma un respaldo por cada empresa con SOLO sus datos y lo deja en dos lugares, el servidor y la nube de la empresa.',
            'Hasta hoy existia un respaldo de la base completa, y sigue existiendo: es el que levanta el sistema entero si pasa algo grave. Lo que no habia era forma de responder "devolveme los datos de ESTA empresa" sin restaurar todo en otro lado y separar a mano lo suyo de lo de los demas.',
            'Todas las empresas comparten las mismas tablas -- asi esta construido el sistema --, asi que el respaldo de un cliente hay que recortarlo. Ese recorte no esta escrito a mano en ninguna lista: se calcula siguiendo las relaciones reales de la base, para que una tabla nueva quede cubierta sola en vez de quedar afuera sin que nadie se entere. Si alguna vez apareciera una tabla que no se puede atribuir a un cliente, el proceso lo denuncia en vez de callarlo.',
            'Se conservan 5 copias por cliente en el servidor y 10 en Nextcloud; las mas viejas se borran. Si una noche algo falla, NO se borra nada: es preferible acumular copias a quedarse sin ninguna.',
            'Cada copia se verifica antes de darla por buena. Un respaldo cortado a la mitad que se da por bueno es peor que no tenerlo.',
            'Si el respaldo de un cliente supera los 85 MB que admite el destino, se guarda igual y queda una alerta en la bitacora y en un correo. No se parte el archivo por su cuenta: eso convertiria la restauracion en un rompecabezas justo el dia peor.',
            'Los datos internos de la casa -- la auditoria del panel, la bitacora de actividad, el backlog -- no viajan en el respaldo de ningun cliente.',
            'La tarea se ve en Tareas programadas, con su horario, su bitacora y la explicacion de por que corre a esa hora y no a otra.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.45',
        'titulo'  => 'Nuevo tipo de cuenta: Cortesia',
        'tag'     => 'datos',
        'items'   => [
            'Se suma un sexto tipo de cuenta, "Cortesia": la que no paga y tampoco es de la casa. Un socio, un contador aliado, una cuenta liberada por un acuerdo.',
            'Antes esas cuentas no tenian donde caer. Marcarlas "Interna" es falso -- no son de la casa -- y marcarlas "De pago" es peor, porque dice que se cobra algo que no se cobra.',
            'Sirve sobre todo para contestar POR QUE una cuenta no factura, que es justo lo que los otros dos tipos escondian cuando alguien pregunta por que el ingreso no cuadra con el numero de clientes.',
            'No cuenta como cuenta comercial en el resumen del listado, y no se le exige plan: puede tener uno liberado o ninguno, y las dos cosas son ciertas.',
            'Requiere aplicar la migracion 049 en la base de datos.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.44',
        'titulo'  => 'Correccion: en la ventana de Acciones no se sabia cual selector era cual',
        'tag'     => 'frontend',
        'items'   => [
            'La ventana de Acciones de una cuenta tiene dos selectores, tipo y plan, y cuando los dos estaban en "Sin definir" -- que es como quedan casi todas las cuentas hoy -- no habia forma de saber cual era cual: el nombre de cada uno existia solo para los lectores de pantalla, invisible para quien esta mirando.',
            'Ahora cada uno lleva su titulo encima: "Tipo de cuenta" y "Plan contratado". Ademas quedan asociados de verdad, asi que apretar el texto abre su selector.',
            'El formulario de alta de una cuenta nueva usa exactamente las mismas palabras, para no obligar a traducir entre una pantalla y otra.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.43',
        'titulo'  => 'Las cuentas ahora tambien dicen que plan tienen: Basico, Pyme o Pro',
        'tag'     => 'datos',
        'items'   => [
            'Al tipo de cuenta se le suma el plan contratado, con los tres de la pagina de venta: Basico, Pyme y Pro. Mas dos respuestas que no son planes: "Sin plan", para una cuenta interna o la de demostracion, y "Sin definir", para las que todavia nadie clasifico.',
            'Son dos datos separados a proposito. El tipo dice que relacion hay -- paga, esta evaluando, es de la casa -- y el plan dice que contrato. Una cuenta en periodo de prueba tambien esta evaluando un plan concreto, que es el dato que dice cuanto va a pagar si se queda; con un solo campo habria que elegir cual de las dos cosas guardar.',
            'En el listado, el plan aparece debajo del tipo, y si una cuenta cobra o esta evaluando pero no declara plan, se marca en rojo. Es un aviso, no una regla: no impide guardarla asi.',
            'Los dos se cambian juntos en la ventana de Acciones, con un solo boton, y quedan en Auditoria como un solo cambio con su antes y su despues.',
            'Arriba del listado se puede filtrar por plan y ver cuantas cuentas hay en cada uno.',
            'El precio y el tope de facturas de cada plan se muestran como referencia al pasar el mouse. El sistema NO cobra y NO controla esos topes: marcar una cuenta como Basico no la detiene en la factura 101. Una prueba automatica compara esos precios con los de la pagina de venta, para que el panel no informe un precio viejo.',
            'Requiere aplicar la migracion 048 en la base de datos.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.42',
        'titulo'  => 'Las acciones sobre una cuenta se agrupan en un boton "Acciones"',
        'tag'     => 'frontend',
        'items'   => [
            'En el listado de cuentas del panel interno, cada fila tiene ahora un boton "Acciones" que abre una ventana con todo lo que se puede hacer sobre esa cuenta: cambiar el tipo (de pago, trial, demostracion, interna) y suspenderla o reactivarla.',
            'Antes los controles estaban sueltos en la tabla -- un selector con su boton en una columna y otro boton al final --, compitiendo con los datos que uno viene a leer. Con seis cuentas ya molestaba.',
            'Ademas mezclaba cosas de peso muy distinto: corregir una etiqueta comercial no se parece en nada a cortarle el servicio a una empresa que esta emitiendo facturas. En la ventana cada accion tiene su bloque y su explicacion, y la peligrosa se distingue por el color y pide confirmacion nombrando la cuenta.',
            'La ventana tambien lleva el email, el numero de cuenta, la fecha de alta y un acceso directo a la ficha completa, para no tener que cerrar y buscar.',
            'Se cierra con la tecla Esc, con la X o haciendo clic afuera, y mientras esta abierta el teclado no se escapa al resto de la pagina.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.41',
        'titulo'  => 'Las cuentas ahora dicen si son de pago, de prueba o de la casa',
        'tag'     => 'datos',
        'items'   => [
            'El listado de cuentas del panel interno tiene una columna nueva: que clase de cuenta es. Cuatro valores -- De pago, Trial, Demostracion e Interna -- y un quinto, "Sin definir", para las que ya existian.',
            'Hasta hoy el sistema no lo sabia. Habia que deducirlo mirando el email: cuatro de las seis cuentas se llaman prueba@algo y son de la casa, pero eso lo sabia quien miraba, no el sistema. Cualquier cifra que saliera de ese listado mezclaba las pruebas internas con los clientes reales.',
            'Arriba del listado quedan los totales por tipo, y se puede filtrar por cualquiera de ellos. Ahi esta la cifra que antes no daba ninguna pantalla: cuantas cuentas son comerciales de verdad.',
            'El alta de una cuenta nueva ahora obliga a elegirlo, sin ninguna opcion premarcada: un valor por defecto seria una respuesta que nadie dio y que a los dos dias se lee como confirmada.',
            'Las cuentas que ya existian NO se etiquetaron a la fuerza: quedaron en "Sin definir", en rojo, para que se elija a mano. La unica excepcion es la cuenta de demostracion, que el sistema si podia deducir con certeza porque su usuario ya estaba marcado como tal.',
            'Cambiar el tipo de una cuenta queda registrado en Auditoria con el antes y el despues: mueve las cifras comerciales, y seis semanas despues nadie se acuerda de quien lo cambio ni por que.',
            'No cambia ningun permiso ni ningun limite: una cuenta marcada como Trial no caduca sola, y el tipo no toca el cupo diario del chat. Es un dato para mirar y filtrar.',
            'Requiere aplicar la migracion 047 en la base de datos.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.40',
        'titulo'  => 'Queda registro de todo lo que se hace en el panel de control',
        'tag'     => 'datos',
        'items'   => [
            'El panel interno tiene una pantalla nueva, "Actividad del panel", que anota cada vez que alguien abre una de sus pantallas: quien, que pantalla, cuando, desde que direccion de internet y como termino.',
            'No reemplaza a Auditoria, que sigue igual. Aquella responde QUE CAMBIO, con el antes y el despues de cada accion; esta responde QUE SE HIZO, incluyendo lo que solo se miro. La diferencia importa: en este panel abrir la ficha de una empresa cliente es ver sus datos, y hasta hoy eso no dejaba ningun rastro. Auditoria tiene 6 registros desde julio, no porque el panel se use poco, sino porque solo seis de las cosas que se hacen ahi cambian algo.',
            'Tambien quedan anotados los intentos rechazados: si alguien entra con una cuenta que no es de administrador y golpea una pantalla del panel interno, se registra el intento y el rechazo.',
            'Nunca se guarda lo que se escribio en un formulario ni lo que se mostro en pantalla: solo la pantalla visitada. Si algun dia una direccion llevara algo parecido a una clave, se guarda tachado.',
            'El registro no se puede editar ni borrar desde el sistema, y abrir la propia pantalla de actividad tambien queda anotado: una bitacora con un hueco justo del tamano de quien la consulta no sirve para lo que existe.',
            'Requiere aplicar la migracion 046 en la base de datos.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.39',
        'titulo'  => 'La lista de migraciones ahora empieza por la mas reciente',
        'tag'     => 'frontend',
        'items'   => [
            'La pantalla de Migraciones ordenaba de la mas antigua a la mas nueva, asi que lo ultimo que se hizo en la base quedaba al final, detras de cuarenta y cuatro lineas que ya no cambian. Ahora la mas reciente aparece primero.',
            'Es el orden que corresponde a lo que se va a mirar ahi: la migracion que se acaba de correr, o la que todavia falta.',
            'El listado que imprime el proceso de despliegue sigue en orden ascendente, porque ahi la lista se lee entera de principio a fin.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.38',
        'titulo'  => 'Correccion: la pantalla nueva de Migraciones no abria',
        'tag'     => 'backend',
        'items'   => [
            'La pantalla que se acababa de publicar no cargaba: mostraba un error tecnico en vez del listado. Ya funciona.',
            'La causa fue el ORDEN en que estaban escritas dos lineas del programa. Una de ellas define donde estan guardadas las migraciones, y quedo escrita mas abajo del punto en que el sistema decide que pantalla mostrar: cuando le tocaba abrir la pantalla, ese dato todavia no existia.',
            'Se agrego una prueba automatica que revisa esa clase de desorden en todo el archivo de rutas, no solo en la pantalla nueva. Como el despliegue corre las pruebas, un error asi ya no puede volver a publicarse.',
            'Se verifico abriendo la pantalla por el mismo camino que usa el navegador -- incluida la vista del contenido de una migracion --, no solo probando las piezas por separado, que fue justamente lo que dejo pasar el error.',
        ],
    ],
    [
        'fecha'   => '2026-08-26',
        'version' => '1.37',
        'titulo'  => 'El panel interno tiene una pantalla propia para las migraciones de la base',
        'tag'     => 'datos',
        'items'   => [
            'Cada cambio de estructura de la base de datos -- una tabla nueva, una columna que se agrega -- se hace con una migracion, y hoy hay 45. Hasta ahora la lista vivia dentro de la pantalla de Base de datos, mezclada con el detalle de las tablas de hoy. Ahora tiene su propio menu, "Migraciones", con su registro completo.',
            'Para cada una dice que hizo, si su efecto ya esta presente en la base que el panel esta usando en este momento, y en que se basa para afirmarlo. La descripcion se lee del propio archivo de la migracion, asi que no puede quedar desactualizada.',
            'Se puede abrir el contenido de cualquier migracion desde la misma pantalla, sin entrar al servidor: ahi esta escrito por que se hizo y que precauciones tomo.',
            'Lo nuevo de verdad es la alerta de descuadre. Las migraciones se anotan en dos lugares que se mantienen por separado, y el error tipico es agregar una y olvidar anotarla: desde ese momento el sistema dice "todo al dia" sobre un cambio que no vigila nadie. Ahora la pantalla compara las dos listas y avisa arriba de todo si no coinciden, y ademas una prueba automatica lo verifica, asi que un descuido asi ya no puede llegar a produccion.',
            'La pantalla solo informa: no aplica migraciones ni tiene un boton para hacerlo. Aplicar sigue siendo una decision humana, con respaldo previo y a una hora elegida.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.36',
        'titulo'  => 'Una prueba automatica vigila que ninguna consulta pueda mezclar dos empresas',
        'tag'     => 'datos',
        'items'   => [
            'Las tablas que guardan facturas, boletas, folios y certificados son compartidas por todas las empresas: lo unico que separa a una de otra es que cada consulta recuerde filtrar por su dueno. Desde la correccion de esta manana la base sabe de quien es cada fila, pero eso no impide que una consulta mal escrita lea las de otro.',
            'Ahora hay una prueba que revisa las 116 consultas del sistema que tocan esas tablas y falla si alguna no filtra. Como el despliegue corre las pruebas, una consulta asi ya no puede llegar a produccion.',
            'Al escribirla se revisaron todas una por una: ninguna estaba mal. Lo que protege es el futuro, no un problema existente.',
            'Seis consultas ven los datos de todas las empresas a proposito -- un contador global del panel interno, un verificador de la estructura de la base, el proceso que consulta al SII por todos los emisores -- y estan declaradas una por una con su motivo escrito. Si alguna deja de corresponder, la prueba tambien avisa: una lista de excepciones que envejece en silencio seria peor que no tenerla.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.35',
        'titulo'  => 'El sistema ya no se despliega si no pasa sus propias pruebas',
        'tag'     => 'devops',
        'items'   => [
            'El proceso de despliegue ahora corre las 453 pruebas automaticas ANTES de construir nada. Si alguna falla, se detiene y no toca el sistema en produccion: el servicio sigue corriendo con la version anterior.',
            'Hasta hoy comprobaba muchas cosas -- que la base estuviera al dia, que los contenedores quedaran sanos, que no se hubieran tocado los otros proyectos del servidor -- todas menos si el codigo funcionaba. Habia que acordarse de mirar.',
            'Las pruebas corren contra el codigo que se va a desplegar, no contra el que ya esta instalado, y en un entorno identico al de produccion: mismas extensiones y misma configuracion. Una prueba que pasa en otro entorno no prueba lo que uno cree.',
            'Se verifico introduciendo un error a proposito: el despliegue se detuvo, como debia.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.34',
        'titulo'  => 'Dos maquinas que instalen el sistema ahora obtienen exactamente lo mismo',
        'tag'     => 'arquitectura',
        'items'   => [
            'Hasta hoy, la version de cada libreria de terceros la decidia la FECHA en que se construia el sistema, no el proyecto: el archivo que fija esas versiones no viajaba en el repositorio. Dos instalaciones hechas con una semana de diferencia podian terminar con librerias distintas, y nadie se enteraba hasta que algo se rompia.',
            'Ese archivo ahora se versiona, asi que una instalacion nueva obtiene exactamente las mismas versiones que estan corriendo hoy. Si alguien pide una libreria incompatible, la construccion se detiene con un error en vez de instalar por su cuenta algo que nadie eligio.',
            'Es la causa de fondo del problema de los informes en PDF que se corrigio en la version anterior: alli se fijo una libreria, aqui se fijan todas.',
            'Verificado construyendo el sistema entero desde cero, sin caches: instala exactamente lo declarado.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.33',
        'titulo'  => 'Los informes en PDF no se van a romper en el proximo despliegue',
        'tag'     => 'arquitectura',
        'items'   => [
            'La libreria que genera los PDF estaba declarada sin version: "la que sea". El sistema funciona hoy con la version 6 solo porque quedo guardada en una cache de construccion del 26 de julio; el dia que esa cache se pierda -- basta con construir en otra maquina -- se habria instalado la version 7, que es una reescritura incompatible, y los informes en PDF habrian dejado de generarse en produccion sin que nadie tocara una linea de codigo.',
            'Ahora la version esta fijada. Se descubrio al dejar la bateria de pruebas automaticas en verde: la prueba de PDF fallaba justo por eso.',
            'La bateria de pruebas pasa de 43 errores y 16 fallos permanentes a cero. Mientras estuvo siempre en rojo no servia para nada: nadie podia mirarla y saber si un cambio nuevo habia roto algo.',
            'Once pruebas quedan marcadas como omitidas, no como fallidas, porque comparan contra archivos reales del SII que no se guardan en el repositorio por contener datos de un contribuyente. Ahora dicen cual archivo falta y por que, en vez de aparecer en rojo como si el sistema estuviera mal.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.32',
        'titulo'  => 'La bitacora de veredictos del SII vuelve a servir para algo',
        'tag'     => 'backend',
        'items'   => [
            'El proceso que cada 15 minutos le pregunta al SII como quedaron los documentos enviados dejo de intentarlo con la cuenta de demostracion, que tiene un certificado de mentira a proposito y por lo tanto no puede consultarse nunca.',
            'Eso escribia tres lineas de error en la bitacora cada 15 minutos desde el 5 de agosto: 36 de las ultimas 60 lineas eran el mismo fallo falso. El problema no era el fallo, era que un problema NUEVO y de verdad iba a aparecer en una lista donde ya nadie miraba.',
            'La cuenta de demostracion conserva sus documentos "sin veredicto", que es lo que muestra esa parte del panel. No se apago nada de la demo para arreglar esto.',
            'El resumen de cada corrida ahora dice cuantos envios se dejaron fuera por ser de demostracion, para que la exclusion se vea y no sea una sorpresa para quien lea la bitacora dentro de un ano.',
        ],
    ],
    [
        'fecha'   => '2026-08-25',
        'version' => '1.31',
        'titulo'  => 'Los documentos tributarios ya no pueden quedar sin dueno',
        'tag'     => 'datos',
        'items'   => [
            'Once tablas del sistema -- las que guardan las facturas y boletas emitidas, los folios del SII, los certificados digitales y los libros -- guardaban informacion de una empresa sin que la base de datos pudiera decir de cual. Ahora cada una de esas filas queda amarrada a su empresa, y la empresa a la cuenta que la contrata.',
            'Se acabo poder guardar un documento a nombre de una empresa que no esta registrada: antes entraba sin protestar y quedaba dando vueltas sin dueno.',
            'Ya no se puede borrar una empresa que tiene documentos emitidos, ni cambiarle el RUT despues de haber emitido. Un documento tributario se emitio a nombre de un RUT y ese dato es parte del documento firmado ante el SII; cambiarlo despues seria alterar la historia. Si alguien lo intenta desde la pantalla de empresa, ahora recibe una explicacion en vez de que el cambio pase en silencio y deje los documentos huerfanos.',
            'La pantalla de base de datos del panel de control lo refleja sola: donde antes marcaba once tablas en rojo, ahora muestra el camino exacto que lleva de cada documento a su cuenta.',
            'Queda una tabla en rojo a proposito, la de los logos de empresa, porque su estructura no permite el mismo amarre. Es la de menor riesgo de todas -- guarda una imagen -- y la pantalla la sigue mostrando en rojo en vez de esconderla.',
        ],
    ],
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
