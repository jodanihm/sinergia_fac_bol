<?php

declare(strict_types=1);

/**
 * Lee el log de una tarea programada y lo deja en condiciones de mostrarse.
 *
 * DE DONDE SALEN ESTOS ARCHIVOS. Son la salida estandar de los crones del host,
 * redirigida por la propia linea de /etc/cron.d. Entran al contenedor del panel
 * como bind mounts de SOLO LECTURA, uno por archivo, declarados en
 * docker-compose.vps.yml. Se montan en la MISMA ruta que tienen en el host
 * (/var/log/sinergia_*.log), y no es cosmetico: asi el campo 'log' de
 * panel/datos/tareas_programadas.php sirve a la vez para leerlo desde aca y
 * para decirle a una persona donde mirarlo por consola. Un solo dato, imposible
 * que las dos mitades se desincronicen.
 *
 * SE MONTAN ARCHIVOS, NO EL DIRECTORIO. /var/log entero traeria al proceso web
 * los logs de auth, de nginx y de todo lo demas que vive en ese host junto a
 * otros cinco proyectos. Tres archivos concretos es todo lo que hace falta.
 *
 * NO SE LEE EL ARCHIVO COMPLETO. El de veredictos ya va en 1,5 MB y crece unos
 * 25 MB al anio (no hay logrotate para estos tres). file() o file_get_contents()
 * cargarian todo en memoria de un worker de php-fpm que tiene 192 MB, para
 * mostrar las ultimas cincuenta lineas. Se abre, se salta hasta TOPE_BYTES
 * antes del final y se lee solo eso.
 *
 * POR ESO SE DESCARTA LA PRIMERA LINEA LEIDA cuando el archivo no cabe entero:
 * el salto cae a mitad de una linea y ese pedazo no es un registro, es basura.
 *
 * QUE PASA SI EL ARCHIVO NO ESTA. Se responde disponible=false con el motivo, y
 * la pantalla lo explica en vez de caerse. No es un caso hipotetico: el mount
 * viaja en el compose, pero el ARCHIVO lo crea el cron del host la primera vez
 * que escribe. En una maquina donde esos crones no esten instalados -- un
 * entorno de desarrollo, un VPS recien armado -- docker crea un directorio
 * vacio en su lugar y no hay bitacora que leer. Castigar con un 500 a quien
 * mira la pantalla por una diferencia de infraestructura seria absurdo.
 */
final class BitacoraTarea
{
    /** Cuanto se lee desde el final del archivo, como mucho. */
    public const TOPE_BYTES = 256 * 1024;

    /** Una linea de estas es un fallo que hay que destacar. */
    private const MARCAS_FALLO = ['FALLO', 'ERROR', 'Error response from daemon'];

    /**
     * El final del log, ya partido en lineas.
     *
     * @return array{disponible:bool, motivo:string, tamano:int, modificado:?int, lineas:list<string>, truncado:bool}
     */
    public static function leer(string $ruta, int $cuantasLineas = 60): array
    {
        $vacio = [
            'disponible' => false,
            'motivo'     => '',
            'tamano'     => 0,
            'modificado' => null,
            'lineas'     => [],
            'truncado'   => false,
        ];

        // No se distingue "no existe" de "no esta montado" adivinando: los dos
        // se ven igual desde adentro del contenedor. La pantalla nombra las dos
        // posibilidades y deja que decida quien sabe como esta el host.
        if (!is_file($ruta)) {
            return ['motivo' => 'el archivo no esta a la vista del panel'] + $vacio;
        }

        if (!is_readable($ruta)) {
            return ['motivo' => 'el archivo existe pero el panel no tiene permiso de lectura'] + $vacio;
        }

        $tamano = (int) @filesize($ruta);
        $mtime  = @filemtime($ruta);
        $manija = @fopen($ruta, 'rb');

        if ($manija === false) {
            return ['motivo' => 'no se pudo abrir el archivo'] + $vacio;
        }

        $truncado = $tamano > self::TOPE_BYTES;

        if ($truncado) {
            fseek($manija, -self::TOPE_BYTES, SEEK_END);
        }

        $texto = (string) stream_get_contents($manija);
        fclose($manija);

        $lineas = preg_split('/\R/', $texto) ?: [];

        // El salto cae a mitad de linea: ese primer pedazo no es un registro.
        if ($truncado && $lineas !== []) {
            array_shift($lineas);
        }

        $lineas = array_values(array_filter($lineas, static fn (string $l): bool => trim($l) !== ''));

        if (count($lineas) > $cuantasLineas) {
            $lineas   = array_slice($lineas, -$cuantasLineas);
            $truncado = true;
        }

        return [
            'disponible' => true,
            'motivo'     => '',
            'tamano'     => $tamano,
            'modificado' => $mtime === false ? null : $mtime,
            'lineas'     => array_values($lineas),
            'truncado'   => $truncado,
        ];
    }

    /**
     * Que clase de linea es: 'fallo', 'resumen' o 'normal'.
     *
     * El orden importa. Una linea de RESUMEN puede traer 'fallidos=3' y esa es
     * justo la que no hay que pintar como si todo estuviera bien, asi que el
     * fallo se pregunta ANTES que el resumen.
     */
    public static function clasificar(string $linea): string
    {
        foreach (self::MARCAS_FALLO as $marca) {
            if (str_contains($linea, $marca)) {
                return 'fallo';
            }
        }

        // 'fallidos=0' es lo normal; cualquier otro numero es una linea que
        // merece el mismo trato que un FALLO suelto.
        if (preg_match('/\bfallidos=([1-9]\d*)\b/', $linea) === 1) {
            return 'fallo';
        }

        if (str_contains($linea, 'RESUMEN')) {
            return 'resumen';
        }

        return 'normal';
    }

    /**
     * La marca de tiempo con la que abre la linea, si la trae.
     *
     * DOS FORMATOS, porque los tres scripts no se pusieron de acuerdo: correos y
     * veredictos escriben '2026-08-25 11:45:04' y ordenes de compra escribe
     * '2026-08-25T11:25:01-04:00'. Reconocer solo uno dejaria a una de las tres
     * pantallas sin poder decir cuando fue la ultima senal.
     */
    public static function momento(string $linea): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z))/', $linea, $m) === 1) {
            try {
                return new DateTimeImmutable($m[1]);
            } catch (Exception $e) {
                return null;
            }
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $linea, $m) === 1) {
            try {
                return new DateTimeImmutable($m[1]);
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * La ultima linea del lote que traiga fecha. Recorre de atras para adelante
     * porque las lineas sin fecha (una traza, un error del daemon de docker)
     * son justo las que suelen quedar al final.
     *
     * @param list<string> $lineas
     */
    public static function ultimaSenal(array $lineas): ?DateTimeImmutable
    {
        for ($i = count($lineas) - 1; $i >= 0; $i--) {
            $momento = self::momento($lineas[$i]);

            if ($momento !== null) {
                return $momento;
            }
        }

        return null;
    }

    /**
     * El veredicto que se pinta arriba de todo: si la tarea viene bien o hay
     * que mirarla.
     *
     * EL SILENCIO NO SIGNIFICA LO MISMO EN LOS TRES LOGS, y esta es la razon de
     * que $regimen exista. El de correos es de EVENTOS a proposito: si no hubo
     * nada que enviar no escribe una linea, y su ultima actividad puede ser de
     * hace tres semanas sin que nada este roto. Los otros dos escriben en cada
     * corrida, asi que ahi el silencio SI es una senal de alarma. Una regla
     * unica -- "sin lineas recientes, alarma" -- pintaria en rojo permanente la
     * tarea de correos, y una pantalla que grita sin motivo se deja de mirar.
     *
     * EL MARGEN ES DE TRES INTERVALOS. Con uno solo, una corrida que tarda un
     * poco mas de la cuenta o un minuto de desfase del reloj bastan para dar
     * alarma. Tres es holgado y sigue avisando dentro de la hora en los crones
     * de 5 y 15 minutos que hay hoy.
     *
     * NO AFIRMA QUE TODO ESTE BIEN, solo que no se ve nada malo EN LO QUE SE
     * ALCANZA A LEER, que son las ultimas lineas del archivo. Por eso el texto
     * dice de donde sale y nunca promete mas que eso.
     *
     * @param 'eventos'|'cada_corrida' $regimen
     * @return array{estado:string, titulo:string, detalle:string}
     */
    public static function veredicto(
        bool $disponible,
        string $motivo,
        string $regimen,
        ?DateTimeImmutable $ultimaSenal,
        DateTimeImmutable $ahora,
        ?int $intervaloSegundos,
        int $fallos,
        int $lineasLeidas = 0
    ): array {
        if (!$disponible) {
            return [
                'estado'  => 'sin_datos',
                'titulo'  => 'No se puede leer la bitacora',
                'detalle' => ucfirst($motivo) . '. El panel la lee por un montaje de solo lectura declarado '
                    . 'en docker-compose.vps.yml; si ese montaje no esta, la tarea igual corre en el '
                    . 'servidor, pero desde aca no se ve.',
            ];
        }

        if ($fallos > 0) {
            $de = $lineasLeidas > 0 ? " de las ultimas {$lineasLeidas}" : '';

            return [
                'estado'  => 'atencion',
                'titulo'  => $fallos === 1
                    ? "1 linea con fallo{$de}"
                    : "{$fallos} lineas con fallo{$de}",
                'detalle' => 'Estan destacadas mas abajo. El conteo es sobre el final de la bitacora, que es lo '
                    . 'que se alcanza a leer desde aca: dice que proporcion de lo reciente viene fallando, no '
                    . 'cuantos fallos hubo en total.',
            ];
        }

        if ($ultimaSenal === null) {
            return [
                'estado'  => 'sin_datos',
                'titulo'  => 'La bitacora esta vacia',
                'detalle' => $regimen === 'eventos'
                    ? 'Este log solo escribe cuando pasa algo, asi que estar vacio es lo esperable mientras no haya trabajo.'
                    : 'Esta tarea deberia escribir una linea en cada corrida, asi que un archivo vacio no es lo esperable.',
            ];
        }

        $hace = self::faltanODesde($ultimaSenal, $ahora);

        if ($regimen === 'eventos') {
            return [
                'estado'  => 'ok',
                'titulo'  => 'Sin novedad',
                'detalle' => "Este log solo escribe cuando pasa algo; el silencio es lo normal. La ultima vez "
                    . "que tuvo trabajo fue hace {$hace}. Que la tarea SE EJECUTA se comprueba en el journal "
                    . 'del cron (journalctl -u cron), no aca.',
            ];
        }

        $margen = $intervaloSegundos !== null ? $intervaloSegundos * 3 : null;

        if ($margen !== null && ($ahora->getTimestamp() - $ultimaSenal->getTimestamp()) > $margen) {
            return [
                'estado'  => 'atencion',
                'titulo'  => "Sin senal desde hace {$hace}",
                'detalle' => 'Esta tarea escribe una linea en cada corrida, asi que a esta altura ya deberia '
                    . 'haber varias mas. Puede estar caido el cron del host, el contenedor, o el script '
                    . 'fallando antes de alcanzar a escribir.',
            ];
        }

        return [
            'estado'  => 'ok',
            'titulo'  => "Corrio hace {$hace}",
            'detalle' => 'Escribe una linea en cada corrida y la ultima llego cuando correspondia. '
                . 'Sin fallos en lo que se alcanza a leer.',
        ];
    }

    /**
     * "hace 3 minutos" dicho en la unidad que corresponda. Misma escala que
     * AgendaCron::faltan(), para que las dos pantallas hablen igual.
     */
    private static function faltanODesde(DateTimeImmutable $antes, DateTimeImmutable $ahora): string
    {
        return AgendaCron::faltan($antes, $ahora);
    }

    /**
     * @param list<string> $lineas
     */
    public static function contarFallos(array $lineas): int
    {
        $n = 0;

        foreach ($lineas as $l) {
            if (self::clasificar($l) === 'fallo') {
                $n++;
            }
        }

        return $n;
    }
}
