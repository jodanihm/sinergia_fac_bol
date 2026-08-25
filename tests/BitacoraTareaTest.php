<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use AgendaCron;
use BitacoraTarea;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/AgendaCron.php';
require_once __DIR__ . '/../panel/src/BitacoraTarea.php';

/**
 * Tests del lector de bitacoras de las tareas programadas.
 *
 * Viven en panel/src/, fuera del autoload PSR-4, y se cargan con require_once
 * explicito -- mismo patron que RutasDelRouterTest. AgendaCron va primero
 * porque BitacoraTarea::veredicto() se apoya en su ::faltan() para decir "hace
 * 3 minutos" con la misma escala que la otra pantalla.
 *
 * LO QUE MAS IMPORTA PROBAR AQUI ES EL SILENCIO. El log de correos calla cuando
 * no hay trabajo y el de veredictos escribe en cada corrida: la misma ausencia
 * de lineas significa "todo normal" en uno y "algo se cayo" en el otro. Una
 * regla unica pintaria de rojo permanente a la tarea de correos, y una pantalla
 * que grita sin motivo se deja de mirar. Por eso hay un test por cada
 * combinacion de regimen y silencio.
 *
 * Los archivos de prueba se crean en el directorio temporal del sistema y se
 * borran en tearDown(): no se usan los logs reales del host, que no existen en
 * la maquina donde corre el test.
 */
final class BitacoraTareaTest extends TestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];
    }

    // ---- clasificar ------------------------------------------------------

    public function testUnaLineaDeFalloSeReconoce(): void
    {
        self::assertSame('fallo', BitacoraTarea::clasificar(
            '2026-08-25 11:45:04 sobre 1009906323   76543210-3 FALLO   Fallo descifrado AES-256-GCM'
        ));
    }

    public function testElErrorDelDaemonDeDockerCuentaComoFallo(): void
    {
        // Pasa de verdad: el cron dispara mientras el contenedor esta abajo por
        // un despliegue, y docker exec escribe su propio error en el log.
        self::assertSame('fallo', BitacoraTarea::clasificar(
            'Error response from daemon: container 41afac7b is not running'
        ));
    }

    /**
     * LA TRAMPA DE ESTA PANTALLA. La linea de RESUMEN trae adentro el contador
     * de fallidos, asi que preguntar por 'RESUMEN' antes que por el fallo
     * pintaria de gris una corrida con tres documentos rotos.
     */
    public function testUnResumenConFallidosEsFalloYNoResumen(): void
    {
        self::assertSame('fallo', BitacoraTarea::clasificar(
            '2026-08-25 11:45:04 RESUMEN consultados=1 rechazados=0 fallidos=3 sin_aviso=0'
        ));
    }

    public function testUnResumenLimpioEsResumen(): void
    {
        self::assertSame('resumen', BitacoraTarea::clasificar(
            '2026-08-05 12:05:06 RESUMEN enviados=2 fallidos=0 omitidos=0 pendientes_restantes=0'
        ));
    }

    public function testUnaLineaCorrienteEsNormal(): void
    {
        self::assertSame('normal', BitacoraTarea::clasificar('2026-08-25T11:45:02-04:00 cola vacia: nada que enviar.'));
    }

    // ---- momento ---------------------------------------------------------

    public function testReconoceLosDosFormatosDeFecha(): void
    {
        // correos y veredictos escriben asi...
        $a = BitacoraTarea::momento('2026-08-25 11:45:04 sobre 30474510 78454034-0 certificacion');
        self::assertNotNull($a);
        self::assertSame('2026-08-25 11:45:04', $a->format('Y-m-d H:i:s'));

        // ...y ordenes de compra asi. Reconocer uno solo dejaria a una de las
        // tres pantallas sin poder decir cuando fue la ultima senal.
        $b = BitacoraTarea::momento('2026-08-25T11:25:01-04:00 cola vacia: nada que enviar.');
        self::assertNotNull($b);
        self::assertSame('2026-08-25 11:25:01', $b->format('Y-m-d H:i:s'));
    }

    public function testUnaLineaSinFechaNoInventaUna(): void
    {
        self::assertNull(BitacoraTarea::momento('Error response from daemon: container is not running'));
    }

    public function testLaUltimaSenalSaltaLasLineasSinFecha(): void
    {
        // Justo el final real del log de correos: dos errores del daemon,
        // sin fecha, despues de la ultima linea con marca de tiempo.
        $lineas = [
            '2026-08-05 12:05:06 RESUMEN enviados=2 fallidos=0',
            'Error response from daemon: container 41afac7b is not running',
            'Error response from daemon: container 0d32e41c is not running',
        ];

        $senal = BitacoraTarea::ultimaSenal($lineas);
        self::assertNotNull($senal);
        self::assertSame('2026-08-05 12:05:06', $senal->format('Y-m-d H:i:s'));
    }

    public function testSinNingunaLineaConFechaNoHaySenal(): void
    {
        self::assertNull(BitacoraTarea::ultimaSenal(['una linea', 'otra linea']));
    }

    // ---- leer ------------------------------------------------------------

    public function testUnArchivoQueNoExisteNoRompeLaPantalla(): void
    {
        $r = BitacoraTarea::leer('/var/log/no_existe_' . __FUNCTION__ . '.log');

        self::assertFalse($r['disponible']);
        self::assertNotSame('', $r['motivo']);
        self::assertSame([], $r['lineas']);
    }

    public function testLeeLasUltimasLineasYSaltaLasVacias(): void
    {
        $ruta = $this->archivoCon("uno\n\ndos\ntres\n");
        $r    = BitacoraTarea::leer($ruta);

        self::assertTrue($r['disponible']);
        self::assertSame(['uno', 'dos', 'tres'], $r['lineas']);
        self::assertFalse($r['truncado']);
    }

    public function testRecortaAlNumeroDeLineasPedido(): void
    {
        $ruta = $this->archivoCon(implode("\n", array_map(static fn (int $i): string => "linea {$i}", range(1, 200))) . "\n");
        $r    = BitacoraTarea::leer($ruta, 10);

        self::assertCount(10, $r['lineas']);
        self::assertSame('linea 191', $r['lineas'][0]);
        self::assertSame('linea 200', $r['lineas'][9]);
        self::assertTrue($r['truncado']);
    }

    /**
     * El de veredictos ya va en 1,5 MB y no tiene logrotate. Leerlo entero para
     * mostrar cincuenta lineas cargaria el archivo completo en un worker de
     * php-fpm que tiene 192 MB.
     */
    public function testDeUnArchivoGrandeSoloLeeElFinal(): void
    {
        $relleno = str_repeat("relleno de una linea larga para inflar el archivo\n", 12000);
        $ruta    = $this->archivoCon($relleno . "2026-08-25 11:45:04 RESUMEN enviados=1 fallidos=0\n");

        self::assertGreaterThan(BitacoraTarea::TOPE_BYTES, filesize($ruta));

        $r = BitacoraTarea::leer($ruta, 5);

        self::assertTrue($r['disponible']);
        self::assertTrue($r['truncado']);
        self::assertSame('2026-08-25 11:45:04 RESUMEN enviados=1 fallidos=0', $r['lineas'][4]);
    }

    /**
     * El salto al final del archivo cae a mitad de una linea. Ese pedazo no es
     * un registro y no puede aparecer en pantalla como si lo fuera.
     */
    public function testDescartaElPedazoDeLineaPartidaPorElSalto(): void
    {
        $ruta = $this->archivoCon(str_repeat("x", BitacoraTarea::TOPE_BYTES) . "\nentera\n");
        $r    = BitacoraTarea::leer($ruta, 50);

        self::assertSame(['entera'], $r['lineas']);
    }

    // ---- veredicto -------------------------------------------------------

    private const AHORA = '2026-08-25 12:00:00';

    public function testSinBitacoraSeDiceQueNoSePuedeLeerYNoQueEsteRota(): void
    {
        $v = BitacoraTarea::veredicto(false, 'el archivo no esta a la vista del panel', 'cada_corrida', null, $this->ahora(), 900, 0);

        self::assertSame('sin_datos', $v['estado']);
        self::assertStringContainsString('docker-compose', $v['detalle']);
    }

    /**
     * EL CASO QUE JUSTIFICA TODO EL CAMPO 'bitacora'. El log de correos lleva
     * semanas sin una linea y eso es lo NORMAL: solo escribe cuando hay trabajo.
     */
    public function testElSilencioDeUnLogDeEventosNoEsAlarma(): void
    {
        $hace20Dias = new DateTimeImmutable('2026-08-05 12:00:00');
        $v = BitacoraTarea::veredicto(true, '', 'eventos', $hace20Dias, $this->ahora(), 300, 0);

        self::assertSame('ok', $v['estado']);
        self::assertSame('Sin novedad', $v['titulo']);
        self::assertStringContainsString('el silencio es lo normal', $v['detalle']);
    }

    public function testElMismoSilencioEnUnLogDeCadaCorridaSiEsAlarma(): void
    {
        $hace20Dias = new DateTimeImmutable('2026-08-05 12:00:00');
        $v = BitacoraTarea::veredicto(true, '', 'cada_corrida', $hace20Dias, $this->ahora(), 900, 0);

        self::assertSame('atencion', $v['estado']);
        self::assertStringContainsString('Sin senal desde hace', $v['titulo']);
    }

    public function testUnaCorridaRecienteEnUnLogDeCadaCorridaEstaBien(): void
    {
        $reciente = new DateTimeImmutable('2026-08-25 11:45:00');
        $v = BitacoraTarea::veredicto(true, '', 'cada_corrida', $reciente, $this->ahora(), 900, 0);

        self::assertSame('ok', $v['estado']);
        self::assertStringContainsString('Corrio hace', $v['titulo']);
    }

    /**
     * Un atraso de menos de tres intervalos NO da alarma: una corrida que tarda
     * de mas o un minuto de desfase del reloj no son un problema.
     */
    public function testUnAtrasoDeDosIntervalosTodaviaNoAlarma(): void
    {
        $haceMediaHora = new DateTimeImmutable('2026-08-25 11:30:00');
        $v = BitacoraTarea::veredicto(true, '', 'cada_corrida', $haceMediaHora, $this->ahora(), 900, 0);

        self::assertSame('ok', $v['estado']);
    }

    public function testConFallosGanaElFalloPorEncimaDeTodoLoDemas(): void
    {
        $reciente = new DateTimeImmutable('2026-08-25 11:45:00');
        $v = BitacoraTarea::veredicto(true, '', 'cada_corrida', $reciente, $this->ahora(), 900, 3, 60);

        self::assertSame('atencion', $v['estado']);
        // El denominador va en el titulo: "3 de las ultimas 60" es un problema
        // en curso, "3 de las ultimas 10.000" es un incidente que ya paso.
        self::assertStringContainsString('3 lineas con fallo de las ultimas 60', $v['titulo']);
    }

    /**
     * Sin intervalo -- porque la expresion de cron no se entendio -- el
     * veredicto NO opina sobre el silencio en vez de inventar un margen.
     */
    public function testSinIntervaloNoSeOpinaSobreElSilencio(): void
    {
        $hace20Dias = new DateTimeImmutable('2026-08-05 12:00:00');
        $v = BitacoraTarea::veredicto(true, '', 'cada_corrida', $hace20Dias, $this->ahora(), null, 0);

        self::assertSame('ok', $v['estado']);
    }

    public function testElIntervaloQueUsaLaPantallaSaleDeLaExpresion(): void
    {
        // Es asi como lo calcula la vista: la distancia entre dos corridas.
        $dos = AgendaCron::proximas('*/15 * * * *', $this->ahora(), 2);

        self::assertSame(900, $dos[1]->getTimestamp() - $dos[0]->getTimestamp());
    }

    // ---- utilidades ------------------------------------------------------

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private function archivoCon(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'bitacora_');
        self::assertIsString($ruta);
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return $ruta;
    }
}
