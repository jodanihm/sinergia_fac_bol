<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que el cron del conciliador viva en el repo y lo instale el despliegue.
 *
 * DE DONDE SALE. /etc/cron.d/sinergia-pagos se creo a mano cuando se puso en
 * marcha el cobro en linea. Funcionaba, pero no estaba en ninguna parte: si el
 * host se reconstruye o se migra, ese archivo no viaja y el conciliador deja de
 * correr SIN QUE NADIE SE ENTERE. No falla nada, no hay error en ningun log:
 * simplemente los pagos cuyo aviso se perdio dejan de recuperarse.
 *
 * Y ese es su unico trabajo. Flow llama UNA vez a la url de confirmacion; si esa
 * llamada se pierde -- una caida de red, un despliegue en el segundo equivocado
 * -- el dinero esta cobrado y el unico que lo va a notar es el conciliador.
 *
 *
 * QUE VIGILA ESTE TEST Y QUE NO
 * -----------------------------------------------------------------------------
 * Vigila el CONTENIDO del archivo canonico -- que siga teniendo el flock, la
 * frecuencia, el script y el log -- y que deploy.sh lo administre de verdad: que
 * verifique despues de escribir, que aborte si no puede, y que en --dry-run no
 * toque /etc. Todo eso se puede comprobar sin ser root y sin tocar el sistema.
 *
 * NO comprueba el archivo instalado en la maquina: eso es estado del host, no del
 * repo, y un test que leyera /etc/cron.d pasaria o fallaria segun donde se
 * ejecute. Lo que garantiza que el host queda bien es la verificacion posterior
 * a la instalacion dentro de deploy.sh, y de esa si se comprueba que existe.
 */
final class CronVersionadoTest extends TestCase
{
    private const CANONICO = __DIR__ . '/../infra/cron.d/sinergia-pagos';

    private static function cron(): string
    {
        $texto = file_get_contents(self::CANONICO);
        self::assertNotFalse($texto, 'el repo tiene que traer infra/cron.d/sinergia-pagos');

        return $texto;
    }

    private static function deploy(): string
    {
        $texto = file_get_contents(__DIR__ . '/../deploy.sh');
        self::assertNotFalse($texto);

        return $texto;
    }

    /** La linea de la tarea, sin comentarios ni PATH. */
    private static function lineaTarea(): string
    {
        foreach (explode("\n", self::cron()) as $linea) {
            if ($linea !== '' && ! str_starts_with($linea, '#') && ! str_starts_with($linea, 'PATH=')) {
                return $linea;
            }
        }

        self::fail('el cron no tiene ninguna linea de tarea');
    }

    // ------------------------------------------------------------------
    //  El archivo canonico
    // ------------------------------------------------------------------

    public function testCorreCada5Minutos(): void
    {
        // No es un numero cualquiera: Flow espera 200 en 15 segundos y no
        // reintenta, asi que la ventana entre que se pierde un aviso y alguien
        // lo nota es exactamente esto.
        self::assertStringStartsWith('*/5 * * * * ', self::lineaTarea());
    }

    public function testCorreComoRoot(): void
    {
        // /etc/cron.d exige el campo de usuario. Sin el, cron ignora la linea en
        // silencio -- que es el modo de fallo de todo este archivo.
        self::assertMatchesRegularExpression('#^\S+ \S+ \S+ \S+ \S+ root #', self::lineaTarea());
    }

    public function testLlevaFlockParaQueDosCorridasNoSeSolapen(): void
    {
        // Una corrida lenta y la siguiente a los 5 minutos consultarian la misma
        // orden a la vez. El -n hace que la segunda se rinda en vez de esperar:
        // no tiene sentido acumular corridas.
        $tarea = self::lineaTarea();

        self::assertStringContainsString('/usr/bin/flock -n', $tarea);
        self::assertStringContainsString('/run/lock/sinergia_conciliar_pagos.lock', $tarea);
    }

    public function testLlamaAlConciliadorDentroDelContenedorDelMotor(): void
    {
        $tarea = self::lineaTarea();

        self::assertStringContainsString('docker exec sinergia_motor', $tarea);
        self::assertStringContainsString('php /app/scripts/conciliar_pagos.php', $tarea);
    }

    public function testEscribeSuBitacoraYTambienLosErrores(): void
    {
        // 2>&1 y no solo >>: sin el, un fallo del docker exec se perderia y el
        // log diria "no paso nada" en vez de "no se pudo entrar al contenedor".
        $tarea = self::lineaTarea();

        self::assertStringContainsString('>> /var/log/sinergia_pagos.log', $tarea);
        self::assertStringContainsString('2>&1', $tarea);
    }

    public function testTodasLasRutasSonAbsolutas(): void
    {
        // cron corre con un entorno minimo. El PATH esta declarado igual, pero un
        // binario con ruta absoluta no depende de que ese PATH sea el correcto.
        $tarea = self::lineaTarea();

        self::assertStringContainsString('/usr/bin/flock', $tarea);
        self::assertStringContainsString('/usr/bin/docker', $tarea);
        self::assertStringContainsString('PATH=', self::cron(), 'y el PATH declarado, por si acaso');
    }

    public function testElArchivoTerminaEnSaltoDeLinea(): void
    {
        // cron ignora la ultima linea si no termina en \n. Es un fallo clasico y
        // silencioso: el archivo se ve bien y la tarea no corre.
        self::assertStringEndsWith("\n", self::cron());
    }

    public function testElScriptQueInvocaExisteEnElRepo(): void
    {
        self::assertFileExists(__DIR__ . '/../scripts/conciliar_pagos.php');
    }

    // ------------------------------------------------------------------
    //  Que deploy.sh lo administre de verdad
    // ------------------------------------------------------------------

    public function testDeployInstalaConDuenoYPermisosExplicitos(): void
    {
        // install y no cp: pone contenido, dueno y permisos de una vez, y escribe
        // a un temporal que renombra, asi que cron nunca ve un archivo a medias.
        self::assertStringContainsString(
            'install -o root -g root -m 0644',
            self::deploy()
        );
    }

    public function testDeployVERIFICA_DespuesDeEscribir(): void
    {
        // install puede volver 0 y dejar algo distinto de lo esperado. Aqui el
        // fallo es silencioso por naturaleza, asi que se comprueba y no se
        // supone: contenido, dueno y permisos.
        $cuerpo = self::cuerpoDeFuncion('verificar_crons');

        self::assertStringContainsString('cmp -s "$origen" "$destino" || falla', $cuerpo);
        self::assertStringContainsString("stat -c '%U:%G'", $cuerpo);
        self::assertStringContainsString("stat -c '%a'", $cuerpo);
        // Los cinco modos de fallo: el archivo no esta en el repo, install
        // devuelve error, el contenido no coincide, el dueno no es root:root, los
        // permisos no son 0644. Ninguno puede degradar a un aviso.
        self::assertSame(
            5,
            substr_count($cuerpo, 'falla '),
            'cada modo de fallo tiene que abortar'
        );
    }

    public function testEnDryRunNoEscribeNada(): void
    {
        // El install tiene que quedar DESPUES del corte de dry-run, o un
        // "solo mira que haria" acabaria tocando /etc.
        $cuerpo = self::cuerpoDeFuncion('verificar_crons');

        $corte   = strpos($cuerpo, 'if [ "$modo" = "dry-run" ]');
        $install = strpos($cuerpo, 'install -o root');

        self::assertNotFalse($corte);
        self::assertNotFalse($install);
        self::assertLessThan($install, $corte, 'el dry-run corta antes de escribir');
        self::assertStringContainsString('No se toca nada.', $cuerpo, 'y lo dice');
    }

    public function testSeLlamaEnLosDosCaminos(): void
    {
        $deploy = self::deploy();

        self::assertStringContainsString('verificar_crons "dry-run"', $deploy);
        self::assertStringContainsString('verificar_crons "real"', $deploy);
    }

    public function testSoloAdministraLosCronsQueDeclaraYNingunOtro(): void
    {
        // Los otros cuatro crons del proyecto siguen viviendo solo en el host. Un
        // despliegue que empezara a reescribirlos todos seria una sorpresa muy
        // cara el dia que uno de ellos no coincidiera.
        $deploy = self::deploy();

        self::assertMatchesRegularExpression("/CRONS_ADMINISTRADOS=\(('[a-z-]+'\s*)+\)/", $deploy);
        preg_match("/CRONS_ADMINISTRADOS=\(([^)]*)\)/", $deploy, $m);
        preg_match_all("/'([a-z-]+)'/", $m[1], $nombres);

        self::assertSame(['sinergia-pagos'], $nombres[1]);

        foreach ($nombres[1] as $nombre) {
            self::assertFileExists(
                __DIR__ . '/../infra/cron.d/' . $nombre,
                "deploy.sh dice administrar '{$nombre}' pero el repo no lo trae"
            );
        }
    }

    public function testNoReiniciaCron(): void
    {
        // /etc/cron.d se relee solo. Un systemctl restart cron en cada despliegue
        // seria tocar un servicio del sistema para nada.
        $cuerpo = self::cuerpoDeFuncion('verificar_crons');

        self::assertStringNotContainsString('systemctl', $cuerpo);
        self::assertStringNotContainsString('service cron', $cuerpo);
    }

    /** El cuerpo de una funcion de shell, entre su '() {' y el '}' de columna 0. */
    private static function cuerpoDeFuncion(string $nombre): string
    {
        $lineas = explode("\n", self::deploy());
        $ini    = null;

        foreach ($lineas as $i => $linea) {
            if (str_starts_with($linea, $nombre . '() {')) {
                $ini = $i;
                break;
            }
        }
        self::assertNotNull($ini, "no se encontro {$nombre}() en deploy.sh");

        $cuerpo = '';
        for ($i = $ini + 1; $i < count($lineas); $i++) {
            if ($lineas[$i] === '}') {
                return $cuerpo;
            }
            $cuerpo .= $lineas[$i] . "\n";
        }

        self::fail("{$nombre}() no cierra");
    }
}
