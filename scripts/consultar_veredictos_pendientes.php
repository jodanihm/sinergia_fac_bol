<?php

declare(strict_types=1);

/**
 * Consulta al SII el veredicto de los envios que todavia no lo tienen, y avisa
 * por correo cuando un sobre vuelve rechazado.
 *
 * USO:
 *   php scripts/consultar_veredictos_pendientes.php [--dry-run] [--tope=N]
 *
 * PENSADO PARA CRON, cada 15 minutos:
 *   *_/15 * * * * docker exec sinergia_motor php /app/scripts/consultar_veredictos_pendientes.php >> /var/log/sinergia_veredictos.log 2>&1
 *   (el guion bajo de *_/15 es para no cerrar este comentario; va sin el)
 *
 *
 * QUE PROBLEMA RESUELVE
 * -----------------------------------------------------------------------------
 * Un cliente emitio 68 facturas exentas en produccion. El sistema las dio por
 * buenas y mando los correos; el SII habia RECHAZADO los siete sobres por un
 * error de caratula. Nadie se entero hasta el dia siguiente, y solo porque
 * alguien entro al portal del SII de casualidad.
 *
 * La causa del rechazo era un dato mal tipeado y se arreglo en minutos. Lo que
 * costo 68 documentos fue que NADIE CONSULTARA EL VEREDICTO: 'enviado' en
 * dte_emitido significa que el SII RECIBIO el sobre (STATUS=0 en DTEUpload), no
 * que lo aceptara, y hasta esta entrega lo unico que consultaba el resultado era
 * una persona apretando un boton en el detalle de UN documento.
 *
 * Este runner es el que cierra ese lazo.
 *
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS   -> conexion MySQL (DB_PORT opcional).
 *   CRYPTO_MASTER_KEY                    -> 64 hex; descifra los certificados.
 *   VEREDICTO_AVISO_EMAIL                -> destino de los avisos de rechazo.
 *                                           SIN ESTA VARIABLE NO HAY AVISOS
 *                                           (ver el bloque de configuracion).
 *   BREVO_API_KEY, CORREO_REMITENTE,
 *   CORREO_REMITENTE_NOMBRE              -> los mismos que usa el runner de
 *                                           correos; BrevoMailer los exige.
 *   VEREDICTO_TOPE_POR_CORRIDA           -> opcional, default 30 sobres.
 *   VEREDICTO_PRESUPUESTO_SEGUNDOS       -> opcional, default 600.
 *   VEREDICTO_DIAS_ATRAS                 -> opcional, default 30.
 *
 * EXIT CODES:
 *   0  la corrida termino sin fallos (incluye "no habia nada que consultar")
 *   1  hubo al menos un sobre que no se pudo consultar
 *   2  configuracion o conexion incompleta
 *   3  otra corrida tiene el candado: esta no proceso nada
 *   4  se agoto el presupuesto de tiempo antes de terminar la lista
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Correo\BrevoMailer;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\RegistroVeredictoSii;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

/**
 * TOPE POR CORRIDA, EN SOBRES. Por que 30:
 *
 *   Una consulta cuesta hasta 3 viajes SOAP al SII -- getSeed, getToken,
 *   getEstUp -- porque SiiAutenticador no cachea el token. Este runner reduce
 *   eso reutilizando el token POR EMISOR dentro de la corrida (ver
 *   tokenDeEmisor), asi que N sobres de M emisores cuestan N + 2M llamadas en
 *   vez de 3N: 30 sobres de un emisor pasan de 90 llamadas a 32.
 *
 *   Aun asi, el numero que manda NO es este sino el PRESUPUESTO DE TIEMPO. El
 *   timeout de cada llamada es de 60 s (el mismo del handler del motor), asi que
 *   el peor caso aritmetico de 30 sobres seria 32 x 60 s = 32 minutos, mas del
 *   doble del intervalo del cron. El tope acota el trabajo; el presupuesto acota
 *   el reloj, y es el que garantiza que la corrida cierre antes de la siguiente.
 */
const TOPE_POR_CORRIDA_DEFAULT = 30;

/**
 * PRESUPUESTO DE TIEMPO DE PARED, EN SEGUNDOS. Por que 600:
 *
 *   El cron corre cada 15 minutos = 900 s. 600 s deja 300 s de margen para que
 *   la corrida cierre -- incluido el sobre que este a mitad de camino, que puede
 *   tardar hasta 60 s mas -- antes de que arranque la siguiente.
 *
 *   NO ES REDUNDANTE CON EL CANDADO. El candado evita que dos corridas se pisen;
 *   el presupuesto evita que UNA corrida quede colgada tanto tiempo que todas
 *   las siguientes reboten contra el candado. Sin el, un SII lento convertiria
 *   este runner en un proceso que corre una vez y ya.
 *
 *   Se comprueba ANTES de cada sobre, nunca a mitad de uno: cortar una consulta
 *   por la mitad no ahorra nada y dejaria el veredicto sin persistir.
 */
const PRESUPUESTO_SEGUNDOS_DEFAULT = 600;

/**
 * VENTANA, EN DIAS. Por que 30:
 *
 *   Un sobre que lleva un mes sin veredicto no lo va a conseguir en la corrida
 *   1.441: o el SII ya respondio algo que no supimos leer, o el track_id es de
 *   otra epoca. Consultarlo cada 15 minutos para siempre solo gastaria llamadas
 *   y taparia con ruido a los sobres recientes, que son los unicos sobre los que
 *   todavia se puede actuar.
 *
 *   LO QUE QUEDA FUERA NO SE OCULTA: el resumen de cada corrida dice cuantos
 *   sobres quedaron excluidos por antiguedad. Un tope silencioso se lee como
 *   "estaba todo cubierto", que es justo la lectura que costo 68 documentos.
 */
const DIAS_ATRAS_DEFAULT = 30;

/** Timeout por llamada HTTP al SII. El mismo que usa el handler del motor. */
const TIMEOUT_SII_SEGUNDOS = 60;

/** Nombre del candado de MySQL. Ver adquirirCandado(). */
const CANDADO = 'sinergia_consulta_veredictos';

function fail(string $msg, int $code = 2): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function linea(string $msg): void
{
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $msg . "\n");
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || trim($v) === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }

    return $v;
}

function enteroDeEnv(string $nombre, int $porDefecto): int
{
    $v = getenv($nombre);
    if ($v === false || trim($v) === '') {
        return $porDefecto;
    }
    if (! ctype_digit(trim($v)) || (int) $v <= 0) {
        fail("{$nombre} debe ser un entero positivo, llego '{$v}'.");
    }

    return (int) $v;
}

function conectarDb(): PDO
{
    $host = requerirEnv('DB_HOST');
    $name = requerirEnv('DB_NAME');
    $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;
    $port = getenv('DB_PORT');
    $port = $port === false ? '3306' : $port;

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        fail('No se pudo conectar a la base: ' . $e->getMessage());
    }
}

/**
 * CANDADO CONTRA CORRIDAS SUPERPUESTAS, con GET_LOCK de MySQL. Mismo mecanismo y
 * mismos motivos que scripts/enviar_correos_pendientes.php, que ya lleva dias
 * corriendo con el: alcance de servidor y no de sistema de archivos, se suelta
 * solo si el proceso muere, y no escribe en disco.
 *
 * Nombre distinto al de correos a proposito: son dos trabajos independientes y
 * no hay ninguna razon para que uno bloquee al otro.
 *
 * timeout 0 = no esperar.
 */
function adquirirCandado(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(:n, 0)');
    $stmt->execute([':n' => CANDADO]);

    return (int) $stmt->fetchColumn() === 1;
}

function soltarCandado(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:n)');
    $stmt->execute([':n' => CANDADO]);
}

/**
 * Cuerpo del aviso de rechazo. Texto plano dentro de un <pre>: lo lee alguien
 * que tiene que actuar rapido, no es una comunicacion al cliente.
 *
 * @param list<array{tipo_dte:int, folio:int}> $documentos
 */
function cuerpoAviso(array $sobre, string $estado, ?string $glosa, array $documentos): string
{
    $lineas = [];
    foreach ($documentos as $d) {
        $lineas[] = sprintf('  tipo %d  folio %d', $d['tipo_dte'], $d['folio']);
    }

    return '<p><strong>El SII rechazo un envio.</strong></p><pre>'
        . htmlspecialchars(sprintf(
            "Emisor    : %s\nAmbiente  : %s\nTrack ID  : %s\nEstado    : %s\nGlosa     : %s\nDocumentos: %d\n\n%s",
            $sobre['rut_emisor'],
            $sobre['ambiente'],
            $sobre['track_id'],
            $estado,
            $glosa ?? '(el SII no devolvio glosa)',
            count($documentos),
            implode("\n", $lineas),
        ))
        . '</pre>'
        . '<p>Los folios de estos documentos ya se consumieron. Revisa el detalle '
        . 'en el panel y corrige antes de reemitir.</p>';
}

// ---------------------------------------------------------------------------
//  Argumentos y configuracion
// ---------------------------------------------------------------------------
$argumentos = array_slice($argv, 1);
$dryRun     = in_array('--dry-run', $argumentos, true);

$tope = enteroDeEnv('VEREDICTO_TOPE_POR_CORRIDA', TOPE_POR_CORRIDA_DEFAULT);
foreach ($argumentos as $a) {
    if (str_starts_with($a, '--tope=')) {
        $n = substr($a, 7);
        if (! ctype_digit($n) || (int) $n <= 0) {
            fail("--tope debe ser un entero positivo, llego '{$n}'.");
        }
        $tope = (int) $n;
    }
}
$presupuestoSeg = enteroDeEnv('VEREDICTO_PRESUPUESTO_SEGUNDOS', PRESUPUESTO_SEGUNDOS_DEFAULT);
$diasAtras      = enteroDeEnv('VEREDICTO_DIAS_ATRAS', DIAS_ATRAS_DEFAULT);

// EL DESTINO DEL AVISO ES OPCIONAL, PERO SU AUSENCIA SE GRITA.
//
// No se usa requerirEnv() a proposito: si faltara la variable, el runner moriria
// con exit 2 y dejaria de consultar veredictos, que es lo mas importante que
// hace. Consultar sin avisar sigue siendo mucho mejor que no consultar.
//
// Pero tampoco se calla: sin destino, cada rechazo detectado escribe una linea
// de advertencia en el log diciendo que el aviso NO salio. Un canal de alerta
// que falla en silencio es peor que no tenerlo, porque genera confianza falsa.
$avisoEmail = trim((string) (getenv('VEREDICTO_AVISO_EMAIL') ?: ''));

$pdo = conectarDb();

// ---------------------------------------------------------------------------
//  Candado
// ---------------------------------------------------------------------------
if (! adquirirCandado($pdo)) {
    linea('CANDADO OCUPADO: ya hay otra corrida en curso. Esta no procesa nada.');
    exit(3);
}

// ---------------------------------------------------------------------------
//  Que sobres tomar
//
//  AGRUPADO POR SOBRE, NO POR DOCUMENTO. El veredicto del SII es por TrackID:
//  una consulta resuelve todos los documentos que comparten ese track. Agrupar
//  aqui es lo que impide que un sobre de 20 facturas gaste 20 consultas
//  identicas -- y con el token reutilizado por emisor, 20 sobres de un emisor
//  cuestan 22 llamadas en total.
//
//  QUE ES UN PENDIENTE:
//    - estado = 'enviado': el SII recibio el sobre y nunca se pregunto mas.
//    - estado IN (en curso): ya se consulto y el SII dijo "todavia estoy en
//      eso" (REC, PRD, SOK...). SIN ESTA SEGUNDA CONDICION el runner
//      abandonaria para siempre cualquier sobre que atrapara a mitad de
//      proceso, porque despues de la primera consulta ya no estaria en
//      'enviado'. Es la trampa mas facil de este diseño.
//
//  Y los estados de veredicto -- EPR, los rechazos, 'desconocido' -- quedan
//  FUERA de las dos condiciones, lo que da gratis dos cosas: no se vuelve a
//  gastar una consulta en un sobre ya resuelto, y el aviso de rechazo sale
//  EXACTAMENTE UNA VEZ por sobre, sin necesidad de una columna "ya avise".
//
//  track_id <> '' ademas de NOT NULL: sin track no hay nada que preguntar. Esas
//  filas no son pendientes, son otro problema, y se cuentan aparte.
// ---------------------------------------------------------------------------
$enCurso      = RegistroVeredictoSii::ESTADOS_EN_CURSO;
$placeholders = implode(',', array_fill(0, count($enCurso), '?'));

$sqlPendientes =
    'SELECT rut_emisor, ambiente, track_id, COUNT(*) AS documentos, MIN(created_at) AS desde '
    . 'FROM dte_emitido '
    . "WHERE (estado = 'enviado' OR estado IN ({$placeholders})) "
    . "  AND track_id IS NOT NULL AND track_id <> '' "
    . '  AND created_at >= (NOW() - INTERVAL ? DAY) '
    . 'GROUP BY rut_emisor, ambiente, track_id '
    . 'ORDER BY desde ASC';

$stmt = $pdo->prepare($sqlPendientes . ' LIMIT ' . $tope);
$stmt->execute([...$enCurso, $diasAtras]);
$sobres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cuantos habria en total sin el tope, para que el resumen diga si quedo trabajo
// para la corrida siguiente.
$stmtTot = $pdo->prepare('SELECT COUNT(*) FROM (' . $sqlPendientes . ') t');
$stmtTot->execute([...$enCurso, $diasAtras]);
$sobresTotales = (int) $stmtTot->fetchColumn();

// Y cuantos quedan fuera por la ventana de dias: el tope que se declara.
$stmtViejos = $pdo->prepare(
    'SELECT COUNT(DISTINCT track_id) FROM dte_emitido '
    . "WHERE (estado = 'enviado' OR estado IN ({$placeholders})) "
    . "  AND track_id IS NOT NULL AND track_id <> '' "
    . '  AND created_at < (NOW() - INTERVAL ? DAY)'
);
$stmtViejos->execute([...$enCurso, $diasAtras]);
$sobresFueraDeVentana = (int) $stmtViejos->fetchColumn();

// ---------------------------------------------------------------------------
//  NO HAY NADA QUE HACER: SILENCIO TOTAL.
//
//  Mismo criterio que enviar_correos_pendientes.php, y por el mismo motivo
//  medido alli: el cron entra 96 veces al dia y casi siempre no hay nada. Un log
//  lleno de "sin pendientes" entierra el unico dia en que si pasa algo. Este
//  archivo es de EVENTOS: sin evento, sin linea.
//
//  El latido del cron no se pierde: cada ejecucion queda en el journal.
//
//  Con --dry-run SIEMPRE se imprime: ese lo corre una persona a mano y un
//  silencio la haria pensar que el script esta roto.
// ---------------------------------------------------------------------------
if ($sobres === []) {
    if (! $dryRun) {
        soltarCandado($pdo);
        exit(0);
    }
    linea(sprintf(
        'RESUMEN consultados=0 rechazados=0 fallidos=0 sobres_restantes=0 '
        . '(sin pendientes; tope=%d ventana=%dd fuera_de_ventana=%d)',
        $tope,
        $diasAtras,
        $sobresFueraDeVentana
    ));
    soltarCandado($pdo);
    exit(0);
}

// ---------------------------------------------------------------------------
//  Dry-run: lista y se va sin llamar al SII ni tocar ninguna fila
// ---------------------------------------------------------------------------
if ($dryRun) {
    linea(sprintf(
        '--- DRY RUN: no se llama al SII y no se modifica ninguna fila (tope=%d, sobres=%d de %d) ---',
        $tope,
        count($sobres),
        $sobresTotales
    ));
    foreach ($sobres as $s) {
        linea(sprintf(
            '  %s %-13s track %-12s %3d documento(s)  desde %s',
            $s['rut_emisor'],
            $s['ambiente'],
            $s['track_id'],
            (int) $s['documentos'],
            $s['desde']
        ));
    }
    linea(sprintf(
        'RESUMEN(dry-run) consultaria=%d sobres (%d documentos) de %d; ventana=%dd fuera_de_ventana=%d',
        count($sobres),
        array_sum(array_map(static fn (array $s): int => (int) $s['documentos'], $sobres)),
        $sobresTotales,
        $diasAtras,
        $sobresFueraDeVentana
    ));
    soltarCandado($pdo);
    exit(0);
}

// ---------------------------------------------------------------------------
//  Dependencias reales
// ---------------------------------------------------------------------------
$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        soltarCandado($pdo);
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$binKey = @hex2bin(requerirEnv('CRYPTO_MASTER_KEY'));
if ($binKey === false || strlen($binKey) !== CertificadoCrypto::KEY_LENGTH) {
    soltarCandado($pdo);
    fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 hex).');
}

$http       = new Client(['timeout' => TIMEOUT_SII_SEGUNDOS, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
$emisorRepo = new MySqlEmisorRepository($pdo, new CertificadoCrypto($binKey));
$autenticador = new SiiAutenticador($http, new XmlSigner());
$consultor    = new SiiConsultor($http);

// El mailer solo se construye si hay a quien avisar. Si su configuracion esta
// incompleta NO se aborta la corrida: se anota y se sigue consultando.
$mailer = null;
if ($avisoEmail !== '') {
    try {
        $mailer = BrevoMailer::desdeEntorno();
    } catch (Throwable $e) {
        linea('*** AVISOS DESACTIVADOS: ' . $e->getMessage() . '. Los rechazos se consultan y se guardan, pero NADIE va a recibir el correo. ***');
    }
}

/**
 * TOKEN REUTILIZADO POR EMISOR DENTRO DE LA CORRIDA.
 *
 * SiiAutenticador no cachea nada: cada obtenerToken() son tres viajes al SII
 * (getSeed, firmar, getToken). Pedirlo una vez por sobre triplicaria el costo de
 * una corrida sin ninguna ganancia -- el token es del EMISOR, no del sobre, y
 * los sobres vienen agrupados justamente por emisor.
 *
 * El cache es una variable local de ESTA corrida y muere con ella: no se toca
 * SiiAutenticador, no se persiste un token en ningun lado, y no hay que razonar
 * sobre su vigencia entre ejecuciones. Si el token expirara a mitad de corrida,
 * la consulta de ese sobre falla, se registra y se reintenta en la siguiente.
 *
 * @var array<string,string> clave "rut|ambiente"
 */
$tokens = [];
$tokenDeEmisor = function (string $rut, Ambiente $amb) use (&$tokens, $emisorRepo, $autenticador): string {
    $clave = $rut . '|' . $amb->value;
    if (! isset($tokens[$clave])) {
        $cert          = $emisorRepo->obtenerCertificado($rut, $amb);
        $tokens[$clave] = $autenticador->obtenerToken($cert, $amb);
    }

    return $tokens[$clave];
};

// ---------------------------------------------------------------------------
//  Consulta
// ---------------------------------------------------------------------------
$inicio      = time();
$consultados = 0;
$rechazados  = 0;
$fallidos    = 0;
$sinAviso    = 0;
$tiempoAgotado = false;

foreach ($sobres as $s) {
    // PRESUPUESTO ANTES DEL SOBRE, nunca a mitad. Ver la constante.
    if (time() - $inicio >= $presupuestoSeg) {
        $tiempoAgotado = true;
        break;
    }

    $rut     = (string) $s['rut_emisor'];
    $ambStr  = (string) $s['ambiente'];
    $trackId = (string) $s['track_id'];
    $nDocs   = (int) $s['documentos'];

    // GUARDA POR SOBRE, NO ALREDEDOR DEL BUCLE. Mismo criterio que el runner de
    // correos: si un emisor tiene el certificado vencido, sus sobres fallan y
    // los de los demas emisores se consultan igual. Envolver el foreach entero
    // convertiria un fallo aislado en una corrida perdida.
    try {
        $amb   = Ambiente::from($ambStr);
        $token = $tokenDeEmisor($rut, $amb);
        $res   = $consultor->consultarEnvio($rut, $trackId, $token, $amb);
    } catch (Throwable $e) {
        // NO SE TOCA dte_emitido: el estado previo queda intacto y el sobre
        // vuelve a salir en la corrida siguiente. Un fallo de consulta no es un
        // veredicto.
        $fallidos++;
        linea(sprintf('sobre %-12s %s FALLO   %s', $trackId, $rut, $e->getMessage()));
        continue;
    }

    $estado = RegistroVeredictoSii::normalizar($res['estado']);
    $glosa  = trim($res['glosa']) !== '' ? trim($res['glosa']) : null;

    RegistroVeredictoSii::persistir($pdo, $rut, $ambStr, $trackId, $estado, $glosa);
    $consultados++;

    // LA RESPUESTA CRUDA AL LOG, SIN PARSEAR.
    //
    // Es el motivo por el que esta linea existe: no tenemos ni una sola
    // respuesta real de getEstUp: los unicos ejemplos del repo son fixtures
    // sinteticos con el RESP_BODY vacio, y ningun documento del SII en docs/
    // describe ese cuerpo. Los contadores de EPR (informados / aceptados /
    // rechazados / reparos) NO SE PARSEAN EN ESTA ENTREGA justamente por eso:
    // adivinar nombres de campos produciria ceros que se leerian como "0
    // rechazados", que es peor que no tener el dato.
    //
    // El plan es acumular respuestas reales aqui y diseñar el parseo sobre
    // ellas. Ver RegistroVeredictoSii: el canal de BOLETAS ya lee esos
    // contadores (BoletaConsultor::resumir) e incluso bloquea anulaciones con
    // ellos, asi que el dato existe; la asimetria es solo nuestra.
    //
    // Mientras tanto vale repetirlo aqui, donde se lee el log: EPR NO SIGNIFICA
    // ACEPTADO. Quiere decir que el SII termino de procesar el sobre.
    linea(sprintf(
        'sobre %-12s %s %-13s estado=%s docs=%d glosa=%s',
        $trackId,
        $rut,
        $ambStr,
        $estado,
        $nDocs,
        $glosa ?? '-'
    ));
    linea('  RAW ' . preg_replace('/\s+/', ' ', trim($res['raw'])));

    if (! RegistroVeredictoSii::esRechazo($estado)) {
        continue;
    }

    $rechazados++;

    // UN AVISO POR SOBRE, NO POR DOCUMENTO. 68 documentos rechazados generan 7
    // correos, uno por envio, y no 68. Y sale UNA sola vez sin necesidad de
    // ninguna columna "ya avise": al persistir el veredicto, el sobre deja de
    // cumplir el WHERE de pendientes y no vuelve a salir.
    if ($mailer === null) {
        $sinAviso++;
        linea(sprintf(
            '  *** RECHAZO SIN AVISO: no hay VEREDICTO_AVISO_EMAIL configurado (o el correo no esta configurado). '
            . '%d documento(s) del track %s quedaron rechazados y nadie va a recibir un correo. ***',
            $nDocs,
            $trackId
        ));
        continue;
    }

    try {
        $stmtDocs = $pdo->prepare(
            'SELECT tipo_dte, folio FROM dte_emitido '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND track_id = :track '
            . 'ORDER BY tipo_dte, folio'
        );
        $stmtDocs->execute([':rut' => $rut, ':amb' => $ambStr, ':track' => $trackId]);
        $documentos = array_map(
            static fn (array $d): array => ['tipo_dte' => (int) $d['tipo_dte'], 'folio' => (int) $d['folio']],
            $stmtDocs->fetchAll(PDO::FETCH_ASSOC)
        );

        $resp = $mailer->enviar(
            destinatarioEmail: $avisoEmail,
            asunto:            sprintf('[SII] Envio RECHAZADO (%s) - %s track %s', $estado, $rut, $trackId),
            htmlCuerpo:        cuerpoAviso($s, $estado, $glosa, $documentos),
        );

        $ok = $resp['status'] >= 200 && $resp['status'] < 300;
        linea(sprintf(
            '  AVISO %s a %s (HTTP %d)',
            $ok ? 'ENVIADO' : 'FALLO',
            $avisoEmail,
            $resp['status']
        ));
        if (! $ok) {
            $sinAviso++;
        }
    } catch (Throwable $e) {
        // Que falle el aviso no invalida el veredicto: ya quedo persistido.
        $sinAviso++;
        linea('  AVISO FALLO ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
//  Resumen: una sola linea, pensada para leerse en un log de cron
// ---------------------------------------------------------------------------
$stmtTot->execute([...$enCurso, $diasAtras]);
$restantes = (int) $stmtTot->fetchColumn();

if ($tiempoAgotado) {
    linea(sprintf(
        '*** PRESUPUESTO DE TIEMPO AGOTADO tras %d s: quedan %d sobres sin consultar en esta corrida. '
        . 'Los toma la siguiente. ***',
        time() - $inicio,
        $restantes
    ));
}

if ($sinAviso > 0) {
    linea(sprintf(
        '*** %d RECHAZO(S) SIN AVISO POR CORREO. El veredicto quedo guardado, pero el aviso no salio: '
        . 'revisa VEREDICTO_AVISO_EMAIL y la configuracion de Brevo. ***',
        $sinAviso
    ));
}

linea(sprintf(
    'RESUMEN consultados=%d rechazados=%d fallidos=%d sin_aviso=%d sobres_restantes=%d '
    . '(tope=%d ventana=%dd fuera_de_ventana=%d tiempo=%ds)',
    $consultados,
    $rechazados,
    $fallidos,
    $sinAviso,
    $restantes,
    $tope,
    $diasAtras,
    $sobresFueraDeVentana,
    time() - $inicio
));

soltarCandado($pdo);

if ($tiempoAgotado) {
    exit(4);
}
exit($fallidos > 0 ? 1 : 0);
