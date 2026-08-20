<?php

declare(strict_types=1);

/**
 * EXPERIMENTO: enviar el Set de Prueba de Boleta por MAULLIN (canal clasico
 * DTEUpload) en vez de por REST/pangal, y guardar la respuesta cruda del SII.
 *
 * POR QUE EXISTE
 * -----------------------------------------------------------------------------
 * El SII observo la postulacion de boleta con esto:
 *
 *   "indicar donde esta realizando el envio de las boletas, ya que el TrackID
 *    de las boletas y del RCOF deberian ser numeros cercanos. Ambos deben ser
 *    enviados mediante el upload a Maullin."
 *
 * Hoy el panel manda cada uno por un canal distinto:
 *   - Set de boletas -> REST, pangal.sii.cl/recursos/v1/boleta.electronica.envio
 *     (handleCertificacionBoletaSetEmitirPost, panel/public/index.php)
 *   - RCOF/RVD       -> SOAP clasico, maullin.sii.cl/cgi_dte/UPL/DTEUpload
 *     (handleCertificacionBoletaRvdEmitirPost, panel/public/index.php)
 *
 * Son dos sistemas con numeracion propia, y por eso los TrackID quedan lejos:
 * 30474510 (8 digitos, pangal) contra 0253423118 (10 digitos, maullin).
 *
 * La razon por la que la boleta esta en REST es un comentario en
 * BoletaFacturador.php:40 -- "Maullin puede rechazar tipo 39 con HED-3-211" --
 * para el que NO HAY NINGUNA EVIDENCIA en el repositorio: la cadena "HED"
 * aparece una sola vez, en ese mismo comentario. No hay XML de respuesta, ni
 * log, ni registro de un rechazo real.
 *
 * Y hay evidencia que apunta al reves: EnvioBOLETA_track_0251322086_folios49-53.xml
 * es un sobre de boletas real (emisor 77724622-4, 2026-06-10) cuyo TrackID
 * tiene la forma de Maullin -- 10 digitos con cero inicial, contiguo al del
 * RCOF. Alguien mando un EnvioBOLETA por Maullin y se lo aceptaron. Lo que no
 * se puede saber de ese archivo es si fue por codigo o a mano por el portal.
 *
 * ESTE SCRIPT CIERRA ESA PREGUNTA. Manda el Set por Maullin y guarda lo que el
 * SII conteste, ACEPTE O RECHACE. Un rechazo con codigo exacto vale tanto como
 * una aceptacion: cualquiera de los dos termina con la especulacion.
 *
 * QUE HACE EXACTAMENTE
 *   BoletaFacturador::emitirLoteClasico() con los 5 CASO de
 *   BoletaSetPruebasBuilder. Ese metodo comparte TODO el camino con el
 *   emitirLote() de produccion (mismo sobre EnvioBOLETA, mismo TED, mismas
 *   firmas): lo unico que cambia son el token (SiiAutenticador en vez de
 *   BoletaAutenticador) y el uploader (SiiUploader en vez de BoletaUploader).
 *
 * OJO CON EL SOBRE: se manda un <EnvioBOLETA> a DTEUpload, que es el endpoint
 * del <EnvioDTE> de factura. Si el SII enruta por elemento raiz, entra; si no,
 * rechaza. ESO ES JUSTAMENTE LO QUE SE QUIERE MEDIR -- no es un descuido.
 *
 * CONSUME FOLIOS REALES, Y NO SE PUEDEN DEVOLVER
 * -----------------------------------------------------------------------------
 * asignarSiguienteFolio() avanza proximo_folio en dte_folio ANTES de enviar. Si
 * el SII rechaza, los folios quedan marcados con emision_exitosa = 0, pero el
 * numero YA SE GASTO: no vuelve atras. Son 5 folios de boleta 39 de
 * certificacion por corrida, salga bien o salga mal.
 *
 * Por eso no basta con correrlo: hay que pedirlo dos veces (ver CONFIRMO).
 *
 * USO
 *   # 1. Sin CONFIRMO: solo muestra que haria. NO toca el SII ni la base.
 *   docker exec sinergia_panel php /app/scripts/probar_boleta_set_maullin.php 78454034-0
 *
 *   # 2. Con CONFIRMO: envia de verdad.
 *   docker exec -e CONFIRMO=si sinergia_panel php /app/scripts/probar_boleta_set_maullin.php 78454034-0
 *
 * SALIDA: dos archivos en la raiz del repo, con RUT y fecha en el nombre:
 *   respuesta_maullin_<rut>_<fecha-hora>.xml   respuesta CRUDA del SII
 *   envio_maullin_<rut>_<fecha-hora>.xml       el EnvioBOLETA que se subio
 *
 * Se guardan TAMBIEN cuando el SII rechaza. Toda esta investigacion costo lo
 * que costo porque en su momento nadie guardo las respuestas.
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

// ---------------------------------------------------------------------------
// Emisores permitidos. LISTA BLANCA A PROPOSITO, no un parametro libre: este
// script gasta folios y habla con el SII. Un typo en el RUT no puede terminar
// tocando un emisor que no se queria.
//
// Ambos estan en certificacion de boleta. Se elige por argumento para que
// quede escrito en el historial del shell cual se uso.
// ---------------------------------------------------------------------------
const EMISORES = [
    '78454034-0' => 'SinergIA SpA',
    '78157243-8' => 'EASY AGENDA SPA',
];

// Forma de los TrackID ya observados, para poder comparar al vuelo. Si el
// envio por Maullin devuelve 10 digitos, entro por el mismo sistema que el
// RCOF y la observacion del SII queda resuelta.
const TRACK_PANGAL_EJEMPLO  = '30474510';    // 8 digitos  - REST/pangal
const TRACK_MAULLIN_EJEMPLO = '0253423118';  // 10 digitos - RCOF por maullin

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
 * Filtra por rut_emisor -- scripts/probar_boleta_set_referencias.php no lo
 * hace porque tiene un unico RUT permitido; aca hay dos, y sin el filtro se
 * mostraria el CAF del emisor equivocado.
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
 * emisor (13407848-0 en SinergIA, otro en Easy Agenda) y copiarlo como
 * constante es como se cuelan los envios firmados por quien no corresponde.
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
        "Uso: php scripts/probar_boleta_set_maullin.php <rut-emisor>\n"
        . '  Emisores permitidos: ' . implode(', ', array_keys(EMISORES))
    );
}

$rutEmisor = $args[0];
if (! array_key_exists($rutEmisor, EMISORES)) {
    fail("RUT no permitido: {$rutEmisor}. Solo " . implode(' o ', array_keys(EMISORES)) . '.');
}

// SOLO CERTIFICACION. No hay argumento de ambiente y no debe haberlo: este
// experimento manda un sobre por un canal que puede rechazarlo. En produccion
// eso serian folios reales de un contribuyente quemados para nada.
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

$folioDesde = $caf['proximo_folio'];
$folioHasta = $folioDesde + $cantidad - 1;
$disponibles = $caf['folio_hasta'] - $folioDesde + 1;

// ---------------------------------------------------------------------------
// Lo que va a pasar, ANTES de que pase
// ---------------------------------------------------------------------------

echo "\n";
echo "=====================================================================\n";
echo " ENVIO DEL SET DE BOLETA POR MAULLIN (canal clasico DTEUpload)\n";
echo "=====================================================================\n";
printf("  Emisor        : %s  %s\n", $rutEmisor, EMISORES[$rutEmisor]);
printf("  Firmante      : %s\n", $rutSender);
printf("  Ambiente      : certificacion\n");
printf("  Destino       : https://maullin.sii.cl/cgi_dte/UPL/DTEUpload\n");
printf("  Sobre         : EnvioBOLETA (%d boletas tipo 39, los 5 CASO del set)\n", $cantidad);
printf("  CAF           : id %d, rango %d-%d\n", $caf['caf_id'], $caf['folio_desde'], $caf['folio_hasta']);
printf("  FOLIOS A USAR : %d a %d  <-- se gastan aunque el SII rechace\n", $folioDesde, $folioHasta);
printf("  Quedarian     : %d folios disponibles despues\n", $disponibles - $cantidad);
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

// CONFIRMO=si. La barrera esta aca y no en una pregunta interactiva a
// proposito: esto se corre con docker exec, donde STDIN no siempre esta
// conectado y un readline devolveria vacio sin que nadie lo note.
//
// Sin CONFIRMO se sale con 0, no con error: leer que haria el script es un uso
// legitimo, no una falla.
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

$marca   = date('Y-m-d_His');
$raiz    = __DIR__ . '/..';
$sufijo  = str_replace('-', '', $rutEmisor) . '_' . $marca;
$rutaEnvio     = "{$raiz}/envio_maullin_{$sufijo}.xml";
$rutaRespuesta = "{$raiz}/respuesta_maullin_{$sufijo}.xml";

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

echo "  Enviando...\n\n";

try {
    $resultado = $facturador->emitirLoteClasico($documentos, $cred);
} catch (SiiAutenticacionException $e) {
    echo "-- FALLO DE AUTENTICACION ------------------------------------------\n";
    echo "  El SII no entrego token por el canal clasico (SiiAutenticador).\n";
    echo "  Glosa: {$e->glosaSii}\n";
    echo "  NO se llego a enviar el sobre; los folios no se gastaron.\n";
    guardar($rutaRespuesta, $e->glosaSii . "\n" . $e->getMessage(), 'respuesta (auth)');
    exit(2);
} catch (EnvioRechazadoException $e) {
    // ESTE CASO NO ES UN FRACASO DEL EXPERIMENTO: es el resultado que cierra
    // la pregunta del HED-3-211. Se imprime y se guarda con el mismo cuidado
    // que una aceptacion.
    echo "-- MAULLIN RECHAZO EL ENVIO ----------------------------------------\n";
    printf("  STATUS  : %s\n", $e->status);
    printf("  TrackID : %s\n", $e->trackId ?? '(ninguno)');
    echo "  DETALLE (texto exacto del SII):\n";
    echo "  " . str_replace("\n", "\n  ", $e->getMessage()) . "\n\n";

    // El cuerpo crudo no viaja entero en la excepcion: SiiUploader mete en el
    // mensaje los <ERROR> del SII, o los primeros 2000 bytes de la respuesta
    // si no hubo ninguno. Es lo que hay -- se guarda tal cual, sin recortar.
    guardar($rutaRespuesta, $e->getMessage(), 'respuesta (rechazo)');

    // El sobre que se subio si esta completo: BoletaFacturador lo vuelca antes
    // de llamar al SII, justamente para poder reintentarlo a mano por el portal.
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

$track  = (string) ($resultado['trackId'] ?? '');
$largo  = strlen($track);

echo "-- MAULLIN ACEPTO EL ENVIO -----------------------------------------\n";
printf("  TrackID : %s\n", $track !== '' ? $track : '(vacio)');
printf("  Digitos : %d\n", $largo);
printf("  Estado  : %s\n", (string) ($resultado['estado'] ?? '(sin estado)'));
printf("  Folios  : %s\n", implode(', ', array_column($resultado['boletas'], 'folio')));
echo "\n";

// La comparacion que motivo todo esto, resuelta en pantalla y no a ojo.
echo "  COMPARACION DE FORMATO\n";
printf("    este envio          : %-12s (%d digitos)\n", $track, $largo);
printf("    RCOF por maullin    : %-12s (%d digitos)\n", TRACK_MAULLIN_EJEMPLO, strlen(TRACK_MAULLIN_EJEMPLO));
printf("    set por pangal      : %-12s (%d digitos)\n", TRACK_PANGAL_EJEMPLO, strlen(TRACK_PANGAL_EJEMPLO));
echo "\n";

if ($largo === strlen(TRACK_MAULLIN_EJEMPLO)) {
    echo "  => MISMO FORMATO QUE EL RCOF. El envio entro por el sistema que el\n";
    echo "     SII esperaba. Falta reenviar el RCOF para que los dos TrackID\n";
    echo "     queden contiguos, y recien ahi responderle al SII.\n";
} else {
    echo "  => El formato NO coincide con el del RCOF. Aceptado, pero conviene\n";
    echo "     revisar por donde entro antes de sacar conclusiones.\n";
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
