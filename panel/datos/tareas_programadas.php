<?php

declare(strict_types=1);

/**
 * TAREAS PROGRAMADAS (CRONES) -- dato mantenido a mano, sin base de datos.
 *
 * Este archivo SOLO devuelve un array: sin logica, sin consultas, sin salida.
 * Mismo trato que changelog.php, flujos.php, documentos.php y pendientes.php.
 *
 * POR QUE A MANO Y NO LEYENDO /etc/cron.d. Seria lo natural y NO SE PUEDE: los
 * crones viven en el host y el panel corre dentro del contenedor
 * sinergia_panel, que no monta /etc/cron.d ni el socket de docker (ver
 * docker-compose.vps.yml). Montarlos para pintar una tabla de solo lectura
 * significaria darle al proceso web una ventana al host y, con el socket, la
 * capacidad de ejecutar en cualquier contenedor. El precio no lo vale.
 *
 * ENTONCES ESTE ARCHIVO PUEDE MENTIR, y hay que saberlo: si alguien edita
 * /etc/cron.d/sinergia-* y no toca esto, la pantalla queda desactualizada sin
 * avisar. Dos cosas acotan el dano:
 *   1. 'expresion' es la UNICA fuente del horario. La frase en castellano y las
 *      proximas corridas las calcula AgendaCron a partir de ella, asi que la
 *      expresion y lo que se ve en pantalla no pueden discrepar entre si.
 *   2. La pantalla muestra el archivo de cron de cada tarea, para que quien
 *      dude tenga a mano contra que comparar en el host.
 *
 * QUE ES CADA CAMPO:
 *   id          clave corta, estable, para anclas y para el orden
 *   nombre      que hace, en lenguaje de usuario
 *   proposito   por que existe la tarea; lo que se rompe para el cliente si no corre
 *   expresion   los cinco campos de cron, TAL CUAL estan en /etc/cron.d
 *   archivo     el archivo de cron en el HOST donde vive la linea
 *   contenedor  donde entra por docker exec
 *   comando     lo que se ejecuta dentro del contenedor
 *   log         donde queda la bitacora. MISMA ruta en el host y dentro del
 *               contenedor del panel: el bind mount de docker-compose.vps.yml
 *               la monta en el mismo sitio, a proposito, para que este dato
 *               sirva para leerla desde el panel y para ir a mirarla a mano
 *   bitacora    'eventos' o 'cada_corrida'. QUE SIGNIFICA EL SILENCIO en ese
 *               log, y no es un detalle: el de correos calla cuando no hay
 *               trabajo (esta escrito y justificado en la cabecera de su
 *               script), asi que semanas sin una linea son NORMALES. Los otros
 *               dos escriben siempre, y ahi el silencio si es alarma. Sin este
 *               campo, la pantalla tendria que elegir una regla unica y
 *               equivocarse con una de las dos familias
 *   nota        lo que hay que saber ANTES de cambiarle la frecuencia
 *
 * VERIFICADO EL 26-08-2026 contra los archivos de /etc/cron.d/sinergia-*.
 */

return [
    [
        'id'         => 'correos',
        'nombre'     => 'Vaciar la cola de correos',
        'proposito'  => 'Envia los documentos que quedaron esperando correo. Sin esta tarea el DTE se '
            . 'emite igual, pero el cliente nunca recibe el PDF y el reclamo llega por telefono.',
        'expresion'  => '*/5 * * * *',
        'archivo'    => '/etc/cron.d/sinergia-correos',
        'contenedor' => 'sinergia_motor',
        'comando'    => 'php /app/scripts/enviar_correos_pendientes.php',
        'log'        => '/var/log/sinergia_correos.log',
        'bitacora'   => 'eventos',
        'nota'       => 'El script esta escrito para correr cada 5 minutos: si encuentra otra corrida en '
            . 'curso se va sin hacer nada en vez de esperar, porque esperar solo apilaria procesos. '
            . 'Cambiar la frecuencia sin leer la cabecera del script rompe ese supuesto.',
    ],
    [
        'id'         => 'ordenes-compra',
        'nombre'     => 'Vaciar la cola de correos de ordenes de compra',
        'proposito'  => 'Manda las ordenes de compra que quedaron pendientes de envio al proveedor.',
        'expresion'  => '*/5 * * * *',
        'archivo'    => '/etc/cron.d/sinergia-ordenes-compra',
        'contenedor' => 'sinergia_panel',
        'comando'    => 'php scripts/enviar_ordenes_compra_pendientes.php',
        'log'        => '/var/log/sinergia_ordenes_compra.log',
        'bitacora'   => 'cada_corrida',
        'nota'       => 'Es la unica de las tres que entra al contenedor del PANEL y no al del motor, y la '
            . 'unica con ruta relativa. Al mover o renombrar el script hay que mirar cual es cual.',
    ],
    [
        'id'         => 'veredictos',
        'nombre'     => 'Consultar el veredicto del SII',
        'proposito'  => 'Pregunta al SII que paso con los documentos enviados y actualiza su estado. Sin '
            . 'esto un documento rechazado se queda mostrandose como "enviado" y nadie se entera.',
        'expresion'  => '*/15 * * * *',
        'archivo'    => '/etc/cron.d/sinergia-veredictos',
        'contenedor' => 'sinergia_motor',
        'comando'    => 'php /app/scripts/consultar_veredictos_pendientes.php',
        'log'        => '/var/log/sinergia_veredictos.log',
        'bitacora'   => 'cada_corrida',
        'nota'       => 'Trabaja con un presupuesto de 600 segundos, calculado para que quepa holgado en '
            . 'los 900 de su intervalo. Bajarle el intervalo a menos de 15 minutos sin bajarle el '
            . 'presupuesto hace que una corrida alcance a la siguiente.',
    ],
    [
        'id'         => 'respaldos-tenants',
        'nombre'     => 'Respaldar la informacion de cada cliente',
        'proposito'  => 'Deja una copia por empresa: solo sus filas, recortadas de la base compartida. '
            . 'Sin esto, devolverle a un cliente sus datos -- o recuperarlos despues de un borrado -- '
            . 'obliga a restaurar la base entera en otro lado y separar a mano lo suyo de lo de los demas.',
        'expresion'  => '40 3 * * *',
        'archivo'    => '/etc/cron.d/sinergia-respaldos',
        // LA UNICA DE LA LISTA QUE NO ENTRA A UN CONTENEDOR. Corre en el host
        // porque ahi estan las tres cosas que necesita y que adentro no hay: el
        // cliente de MySQL (via docker exec al contenedor de la base), el disco
        // donde se guardan las copias y la salida a internet hacia Nextcloud.
        'contenedor' => '(el host, como root)',
        'comando'    => '/data/sinergia/facturacion/scripts/respaldar_tenants.sh',
        'log'        => '/var/log/sinergia_respaldos.log',
        'bitacora'   => 'cada_corrida',
        'nota'       => 'Guarda 5 copias por cliente en el servidor y 10 en Nextcloud, y borra las mas '
            . 'viejas -- pero NO borra nada si la corrida tuvo algun fallo. Si el respaldo de un cliente '
            . 'pasa los 85 MB que admite el destino, se guarda igual y queda una alerta en el log y en un '
            . 'correo: no se parte el archivo por su cuenta. Las 03:40 estan elegidas para caer despues '
            . 'del respaldo COMPLETO de la base (03:17, /etc/cron.d/easyagenda-mysql-backup) y antes del '
            . 'de licitaalerta (04:30); moverla sin mirar esos dos deja dos volcados peleando por el mismo '
            . 'MySQL. No reemplaza al respaldo completo: aquel es el que levanta el sistema entero.',
    ],
];
