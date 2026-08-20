<?php

declare(strict_types=1);

/**
 * EXPERIMENTO: enviar el Set de Prueba de Boleta por PANGAL (canal REST propio
 * de boleta) y guardar la respuesta cruda del SII.
 *
 * GEMELO DE scripts/probar_boleta_set_maullin.php. Mismos candados, misma
 * evidencia guardada, mismo set. LO UNICO QUE CAMBIA ES EL CANAL:
 *
 *   este script  -> emitirLote()         -> apicert (token) + pangal (envio)
 *   el de al lado-> emitirLoteClasico()  -> maullin (token SOAP) + DTEUpload
 *
 * POR QUE EXISTE
 * -----------------------------------------------------------------------------
 * Hay una contradiccion sin resolver entre dos indicaciones del propio SII:
 *
 *   - Por correo nos dijeron que el set de boletas y el RCOF "deben ser
 *     enviados mediante el upload a Maullin".
 *   - Su spec de boleta (docs/44_API_Boleta_Electronica_OpenAPI_Spec.yaml)
 *     define pangal/rahue como el canal de envio de boleta, y no tiene ningun
 *     endpoint de ConsumoFolios.
 *
 * Lo medido hasta ahora encaja con las dos lecturas:
 *   - Por MAULLIN el sobre entra (STATUS 0 + TrackID de 10 digitos) pero la
 *     validacion posterior devuelve RCT "Rechazado por Error en Caratula".
 *   - Por PANGAL el sobre pasaba la caratula y moria mas adelante, en la
 *     revision del set ("El Documento no esta en el envio").
 *
 * Correr LOS DOS el mismo dia, con el MISMO XML, es la unica forma de que la
 * respuesta al SII lleve evidencia propia en vez de una teoria. Sirva o no
 * sirva, el resultado se adjunta.
 *
 * OJO: ESTE ENVIO NO ES UNA REPETICION EXACTA DEL DE JULIO. El generador ahora
 * pone TpoDocRef=SET en la referencia (punto I.6 del instructivo del SII, ver
 * BoletaSetPruebasBuilder); los envios de julio por pangal no lo llevaban. O
 * sea que si este pasa la revision del set, no se sabra si fue por el canal o
 * por la referencia corregida. Es una limitacion conocida de esta prueba, y se
 * acepta a proposito: el objetivo es comparar CANALES el mismo dia, no
 * reproducir julio.
 *
 * DIFERENCIA DE TRANSPORTE QUE CONVIENE SABER: BoletaUploader NO reintenta.
 * SiiUploader (el de maullin) reintenta hasta 4 veces ante fallo de conexion;
 * aca un timeout es un intento perdido, con sus folios ya gastados.
 *
 * CONSUME FOLIOS REALES, Y NO SE PUEDEN DEVOLVER
 * -----------------------------------------------------------------------------
 * Igual que el de maullin: asignarSiguienteFolio() avanza proximo_folio ANTES
 * de enviar. Si el SII rechaza, los folios quedan con emision_exitosa = 0 pero
 * el numero YA SE GASTO. Son 5 folios de boleta 39 de certificacion por
 * corrida, salga bien o salga mal.
 *
 * USO
 *   # 1. Sin CONFIRMO: solo muestra que haria. NO toca el SII ni la base.
 *   docker exec sinergia_panel php /app/scripts/probar_boleta_set_pangal.php 78454034-0
 *
 *   # 2. Con CONFIRMO: envia de verdad.
 *   docker exec -e CONFIRMO=si sinergia_panel php /app/scripts/probar_boleta_set_pangal.php 78454034-0
 *
 * SALIDA: dos archivos en la raiz del repo, con el CANAL, el RUT y la fecha en
 * el nombre -- el canal va primero justamente para no confundirlos con los del
 * script de maullin al correr los dos seguidos:
 *   respuesta_pangal_<rut>_<fecha-hora>.json  respuesta CRUDA del SII (JSON)
 *   envio_pangal_<rut>_<fecha-hora>.xml       el EnvioBOLETA que se subio
 *
 * Se guardan TAMBIEN cuando el SII rechaza.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;
use Plantiflex\FacturacionCl\Sii\BoletaSetPruebasBuilder;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

/** Etiqueta del canal. Se imprime en el banner y va en el nombre de los archivos. */
const CANAL = 'PANGAL';

// Lista blanca de emisores. Mismo criterio que el script de maullin: este
// script gasta folios y habla con el SII, un typo en el RUT no puede terminar
// tocando un emisor que no se queria.
const EMISORES = [
    '78454034-0' => 'SinergIA SpA',
    '78157243-8' => 'EASY AGENDA SPA',
];

// Forma de los TrackID ya observados. Por pangal se esperan 8 digitos; 10 seria
// la forma de maullin y querria decir que se corrio el script equivocado.
const TRACK_PANGAL_EJEMPLO  = '30474510';    // 8 digitos  - REST/pangal
const TRACK_MAULLIN_EJEMPLO = '0253423118';  // 10 digitos - maullin/DTEUpload

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }
    return $v;
}

function conectarDb(): PDO
{
    return new PDO(
        'mysql:host=' . requerirEnv('DB_HOST')
            . ';port=' . (getenv('DB_PORT') ?: '3306')
            . ';dbname=' . requerirEnv('DB_NAME')
            . ';charset=utf8mb4',
        requerirEnv('DB_USER'),
        getenv('DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function crearCrypto(): CertificadoCrypto
{
    $bin = @hex2bin(requerirEnv('CRYPTO_MASTER_KEY'));
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        fail('CRYPTO_MASTER_KEY invalida.');
    }
    return new CertificadoCrypto($bin);
}

/**
 * Estado del CAF de boleta 39 en certificacion PARA ESTE EMISOR.
 *
 * @return array{caf_id:int, folio_desde:int, folio_hasta:int, proximo_folio:int}
 */
function cafActivo(PDO $pdo, string $rutEmisor): array
{
    $stmt = $pdo->prepare(
        "SELECT f.caf_id, c.folio_desde, c.folio_hasta, f.proximo_folio
           FROM dte_folio f
           JOIN dte_caf c ON c.id = f.caf_id
          WHERE f.rut_emisor    = :rut
            AND f.ambiente      = 'certificacion'
            AND f.tipo_dte      = 39
            AND f.proximo_folio <= f.folio_hasta
          ORDER BY c.folio_desde ASC
          LIMIT 1"
    );
    $stmt->execute([':rut' => $rutEmisor]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        fail("No hay CAF de boleta 39 con folios disponibles en certificacion para {$rutEmisor}.");
    }
    return array_map('intval', $row);
}

/**
 * RUT del firmante, desde dte_certificado. NUNCA hardcodeado: es distinto por
 * emisor y copiarlo como constante es como se cuelan los envios firmados por
 * quien no corresponde.
 */
function rutSenderDe(PDO $pdo, string $rutEmisor): string
{
    $stmt = $pdo->prepare(
        'SELECT rut_sender FROM dte_certificado
          WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => 'certificacion']);
    $rut = $stmt->fetchColumn();

    if ($rut === false || trim((string) $rut) === '') {
        fail("El certificado de {$rutEmisor} en certificacion no tiene rut_sender.");
    }
    return (string) $rut;
}

/** Guarda contenido y avisa. Best-effort explicito: si no se puede escribir, se dice. */
function guardar(string $ruta, string $contenido, string $rotulo): void
{
    if (@file_put_contents($ruta, $contenido) === false) {
        fwrite(STDERR, "AVISO: no se pudo guardar {$rotulo} en {$ruta}\n");
        return;
    }
    printf("  %-22s %s (%d bytes)\n", $rotulo, $ruta, strlen($contenido));
}

// ---------------------------------------------------------------------------
// Argumentos y candados
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 1) {
    fail(
        "Uso: php scripts/probar_boleta_set_pangal.php <rut-emisor>\n"
        . '  Emisores permitidos: ' . implode(', ', array_keys(EMISORES))
    );
}

$rutEmisor = $args[0];
if (! array_key_exists($rutEmisor, EMISORES)) {
    fail("RUT no permitido: {$rutEmisor}. Solo " . implode(' o ', array_keys(EMISORES)) . '.');
}

// SOLO CERTIFICACION. No hay argumento de ambiente y no debe haberlo.
$ambiente = Ambiente::Certificacion;

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach ([$certTls, $keyTls] as $archivo) {
    if (! is_file($archivo) || ! is_readable($archivo)) {
        fail("Certificado TLS mutuo no disponible: {$archivo}");
    }
}

$pdo       = conectarDb();
$crypto    = crearCrypto();
$caf       = cafActivo($pdo, $rutEmisor);
$rutSender = rutSenderDe($pdo, $rutEmisor);

$documentos = (new BoletaSetPruebasBuilder())->construirDocumentos();
$cantidad   = count($documentos);

$folioDesde  = $caf['proximo_folio'];
$folioHasta  = $folioDesde + $cantidad - 1;
$disponibles = $caf['folio_hasta'] - $folioDesde + 1;

// ---------------------------------------------------------------------------
// Lo que va a pasar, ANTES de que pase
// ---------------------------------------------------------------------------

echo "\n";
echo "=====================================================================\n";
echo " ENVIO DEL SET DE BOLETA POR " . CANAL . " (canal REST propio de boleta)\n";
echo "=====================================================================\n";
printf("  Emisor        : %s  %s\n", $rutEmisor, EMISORES[$rutEmisor]);
printf("  Firmante      : %s\n", $rutSender);
printf("  Ambiente      : certificacion\n");
printf("  Token         : https://apicert.sii.cl/recursos/v1/boleta.electronica.token\n");
printf("  Destino       : https://pangal.sii.cl/recursos/v1/boleta.electronica.envio\n");
printf("  Sobre         : EnvioBOLETA (%d boletas tipo 39, los 5 CASO del set)\n", $cantidad);
printf("  CAF           : id %d, rango %d-%d\n", $caf['caf_id'], $caf['folio_desde'], $caf['folio_hasta']);
printf("  FOLIOS A USAR : %d a %d  <-- se gastan aunque el SII rechace\n", $folioDesde, $folioHasta);
printf("  Quedarian     : %d folios disponibles despues\n", $disponibles - $cantidad);
echo "\n";
echo "  OJO: este es el script de " . CANAL . ". El de Maullin es\n";
echo "       probar_boleta_set_maullin.php -- son dos envios distintos.\n";
echo "\n";

if ($disponibles < $cantidad) {
    fail(sprintf(
        'No alcanzan los folios: se necesitan %d y quedan %d (proximo %d, tope %d).',
        $cantidad,
        $disponibles,
        $folioDesde,
        $caf['folio_hasta'],
    ));
}

// CONFIRMO=si. La barrera va en variable de entorno y no en una pregunta
// interactiva porque esto se corre con docker exec, donde STDIN no siempre esta
// conectado y un readline devolveria vacio sin que nadie lo note.
if (getenv('CONFIRMO') !== 'si') {
    echo "  MODO LECTURA. No se envio nada y no se toco la base.\n";
    echo "  Para enviar de verdad, repetir con CONFIRMO=si:\n\n";
    printf(
        "    docker exec -e CONFIRMO=si sinergia_panel php /app/scripts/%s %s\n\n",
        basename(__FILE__),
        $rutEmisor,
    );
    exit(0);
}

// ---------------------------------------------------------------------------
// Envio
// ---------------------------------------------------------------------------

$marca  = date('Y-m-d_His');
$raiz   = __DIR__ . '/..';
$sufijo = str_replace('-', '', $rutEmisor) . '_' . $marca;
$rutaEnvio     = "{$raiz}/envio_pangal_{$sufijo}.xml";
$rutaRespuesta = "{$raiz}/respuesta_pangal_{$sufijo}.json";

$folios = new MySqlFolioRepository(
    $pdo,
    fn (string $c): string => $crypto->descifrar($c),
    cryptoKek: $crypto,
);

$facturador = new BoletaFacturador(
    new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
    $folios,
    new MySqlEmisorRepository($pdo, $crypto),
    dteEmitido: new MySqlDteEmitidoRepository($pdo),
);

$cred = new Credenciales(
    rutEmisor: $rutEmisor,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: $rutSender,
);

echo "  Enviando por " . CANAL . "...\n\n";

try {
    // LA UNICA LINEA QUE DIFIERE DEL SCRIPT DE MAULLIN: emitirLote() usa
    // BoletaAutenticador + BoletaUploader (REST); emitirLoteClasico() usa
    // SiiAutenticador + SiiUploader (SOAP/DTEUpload). Todo lo demas -- sobre,
    // TED, firmas, persistencia -- es el mismo emitirLoteInterno().
    $resultado = $facturador->emitirLote($documentos, $cred);
} catch (SiiAutenticacionException $e) {
    echo "-- FALLO DE AUTENTICACION ------------------------------------------\n";
    echo "  apicert no entrego token (BoletaAutenticador).\n";
    echo "  Glosa: {$e->glosaSii}\n";
    echo "  NO se llego a enviar el sobre; los folios no se gastaron.\n";
    guardar($rutaRespuesta, $e->glosaSii . "\n" . $e->getMessage(), 'respuesta (auth)');
    exit(2);
} catch (EnvioRechazadoException $e) {
    // NO ES UN FRACASO DEL EXPERIMENTO: es la mitad del dato que se vino a
    // buscar. Se imprime y se guarda con el mismo cuidado que una aceptacion.
    echo "-- " . CANAL . " RECHAZO EL ENVIO ------------------------------------\n";
    printf("  ESTADO  : %s\n", $e->status);
    printf("  TrackID : %s\n", $e->trackId ?? '(ninguno)');
    echo "  DETALLE (texto exacto del SII):\n";
    echo "  " . str_replace("\n", "\n  ", $e->getMessage()) . "\n\n";

    // El cuerpo crudo no viaja entero en la excepcion: BoletaUploader mete en el
    // mensaje los primeros 2000 bytes de la respuesta. Es lo que hay -- se
    // guarda tal cual, sin recortar mas.
    guardar($rutaRespuesta, $e->getMessage(), 'respuesta (rechazo)');

    // El sobre subido si esta completo: BoletaFacturador lo vuelca antes de
    // llamar al SII, para poder reintentarlo a mano por el portal.
    $volcado = "{$raiz}/envio_boleta_debug.xml";
    if (is_file($volcado)) {
        guardar($rutaEnvio, (string) file_get_contents($volcado), 'envio subido');
    }

    echo "\n  Los folios {$folioDesde}-{$folioHasta} quedaron gastados (emision_exitosa = 0).\n";
    echo "  Este rechazo ES el dato: guardalo y adjuntalo en la respuesta al SII.\n\n";
    exit(3);
} catch (Throwable $e) {
    echo "-- ERROR INESPERADO ------------------------------------------------\n";
    echo '  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    guardar($rutaRespuesta, get_class($e) . ': ' . $e->getMessage(), 'respuesta (error)');
    exit(4);
}

// ---------------------------------------------------------------------------
// Aceptado
// ---------------------------------------------------------------------------

$track = (string) ($resultado['trackId'] ?? '');
$largo = strlen($track);

echo "-- " . CANAL . " ACEPTO EL ENVIO ----------------------------------------\n";
printf("  TrackID : %s\n", $track !== '' ? $track : '(vacio)');
printf("  Digitos : %d\n", $largo);
printf("  Estado  : %s\n", (string) ($resultado['estado'] ?? '(sin estado)'));
printf("  Folios  : %s\n", implode(', ', array_column($resultado['boletas'], 'folio')));
echo "\n";

echo "  COMPARACION DE FORMATO\n";
printf("    este envio          : %-12s (%d digitos)\n", $track, $largo);
printf("    pangal (esperado)   : %-12s (%d digitos)\n", TRACK_PANGAL_EJEMPLO, strlen(TRACK_PANGAL_EJEMPLO));
printf("    maullin             : %-12s (%d digitos)\n", TRACK_MAULLIN_EJEMPLO, strlen(TRACK_MAULLIN_EJEMPLO));
echo "\n";

if ($largo === strlen(TRACK_MAULLIN_EJEMPLO)) {
    echo "  => OJO: el TrackID tiene forma de MAULLIN, no de pangal. Revisa que\n";
    echo "     hayas corrido el script que querias antes de sacar conclusiones.\n";
} else {
    echo "  => Formato de pangal, como se esperaba. El estado 'REC' solo dice\n";
    echo "     RECIBIDO: el veredicto real se consulta despues por trackid.\n";
}
echo "\n";

echo "  Archivos guardados:\n";
guardar($rutaEnvio, (string) ($resultado['xml'] ?? ''), 'envio subido');
guardar(
    $rutaRespuesta,
    (string) ($resultado['raw']['raw'] ?? json_encode($resultado['raw'] ?? [], JSON_PRETTY_PRINT)),
    'respuesta cruda',
);
echo "\n";
