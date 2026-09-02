<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: la emision de NOTAS DE CREDITO (61), SIN QUEMAR FOLIOS.
 *
 * ------------------------------------------------------------------------
 * LA GARANTIA, QUE ES EL PUNTO DE ESTE SCRIPT
 *
 * NO llama a asignarSiguienteFolio(). NO escribe en dte_folio, dte_folio_log,
 * dte_emitido ni dte_idempotencia. NO sube nada al SII. Al terminar, el
 * contador de folios esta EXACTAMENTE donde estaba -- y el script lo comprueba
 * el mismo, releyendo dte_folio al final y comparandolo con lo que leyo al
 * principio (PANTALLA 8): si algo lo movio, aborta y lo dice.
 *
 * COMO SE CONSIGUE. El unico paso de emitir() que consume es el punto 2
 * ("Asignar folio: a partir de aqui queda QUEMADO"). Todo lo demas -- reunir
 * CAF, certificado, datos del emisor y token, construir el DTE, el TED, las
 * firmas -- es inocuo. Aqui se hace todo eso reemplazando ese unico paso por
 * una LECTURA de dte_folio.proximo_folio: se construye el DTE con el folio que
 * SE USARIA, sin tomarlo. El documento resultante se firma de verdad y se
 * valida de verdad, pero muere en /tmp.
 *
 * POR QUE NO BASTA CON probar_emision_local.php. Ese arnes SI asigna folio (lo
 * dice su cabecera: "asignacion de folio"), asi que cada corrida gasta uno.
 * Con dos folios de NC disponibles, dos corridas dejarian al emisor sin poder
 * emitir una nota de credito de verdad.
 *
 * ------------------------------------------------------------------------
 * QUE VERIFICA, EN ORDEN
 *
 *   1. Contador de folios de tipo 61: hay CAF activo y quedan folios; el
 *      contador es coherente con lo realmente emitido y con la bitacora.
 *   2. El CAF descifra, es del emisor y del tipo correctos, su rango coincide
 *      con el contador y su llave privada carga.
 *   3. El certificado descifra, carga y NO esta vencido.
 *   4. Los datos del emisor estan completos (los que exige la caratula).
 *   5. Hay un documento real al que referenciar (una NC sin referencia la
 *      rechaza el SII, REF-3-415).
 *   6. Se construye y FIRMA una NC completa por el MISMO camino que
 *      SiiDirectoFacturador::emitir() -- esqueleto de firma, congelado,
 *      digest y firma, en ese orden -- con el folio leido, no tomado.
 *   7. El XML resultante valida contra el XSD OFICIAL del SII
 *      (docs/18_Schema_XML_DTE/EnvioDTE_v10.xsd).
 *   8. El contador de folios sigue intacto.
 *
 * LA AUTENTICACION CONTRA EL SII es opcional y va aparte (--con-sii): pedir un
 * token es una operacion de LECTURA y no consume folios, pero sale a internet
 * y no siempre se quiere. Sin ella el arnes es 100% local.
 *
 * ------------------------------------------------------------------------
 * USO
 *   php scripts/verificar_nota_credito.php <ambiente> <rut_emisor> [--con-sii]
 *
 * EJEMPLO (dentro del contenedor del motor, que ya trae las variables):
 *   docker exec sinergia_motor php scripts/verificar_nota_credito.php produccion 78225195-3
 *
 * VARIABLES DE ENTORNO: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT (opc),
 * CRYPTO_MASTER_KEY (32 bytes en HEX).
 *
 * SALIDA: 0 si todo pasa, 1 si algo falla. Cada pantalla dice OK o FALLA.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteBuilder;
use Plantiflex\FacturacionCl\Sii\Rut;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\TedBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

const NS_SII  = 'http://www.sii.cl/SiiDte';
const RUT_SII = '60803000-K';
const XSD     = __DIR__ . '/../docs/18_Schema_XML_DTE/EnvioDTE_v10.xsd';

$fallos = 0;

function pantalla(string $titulo): void
{
    echo "\n" . str_repeat('=', 78) . "\n  {$titulo}\n" . str_repeat('=', 78) . "\n";
}

function ok(string $msg): void
{
    echo "  OK    {$msg}\n";
}

function falla(string $msg): void
{
    global $fallos;
    $fallos++;
    echo "  FALLA {$msg}\n";
}

function dato(string $msg): void
{
    echo "        {$msg}\n";
}

function abortar(string $msg): never
{
    fwrite(STDERR, "\nABORTA: {$msg}\n");
    exit(1);
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        abortar("falta la variable de entorno {$nombre}.");
    }
    return $v;
}

function conectarDb(): PDO
{
    $pass = getenv('DB_PASS');
    $port = getenv('DB_PORT');
    try {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                requerirEnv('DB_HOST'),
                $port === false || $port === '' ? '3306' : $port,
                requerirEnv('DB_NAME'),
            ),
            requerirEnv('DB_USER'),
            $pass === false ? '' : $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        abortar('no se pudo conectar a la base: ' . $e->getMessage());
    }
}

/** Lee el contador SIN tocarlo. Es el reemplazo de asignarSiguienteFolio(). */
function leerContador(PDO $pdo, string $rut, int $tipo, string $ambiente): ?array
{
    $q = $pdo->prepare(
        'SELECT f.proximo_folio, f.folio_hasta, c.estado, c.folio_desde, c.id AS caf_id '
        . 'FROM dte_folio f INNER JOIN dte_caf c ON c.id = f.caf_id '
        . 'WHERE f.rut_emisor = :rut AND f.tipo_dte = :tipo AND f.ambiente = :amb '
        . 'ORDER BY c.folio_desde ASC'
    );
    $q->execute([':rut' => $rut, ':tipo' => $tipo, ':amb' => $ambiente]);
    $filas = $q->fetchAll(PDO::FETCH_ASSOC);

    // El mismo criterio de MySqlFolioRepository::obtenerCafActivo(): el CAF
    // activo de menor folio_desde que aun tenga folios.
    foreach ($filas as $f) {
        if ($f['estado'] === 'activo' && (int) $f['proximo_folio'] <= (int) $f['folio_hasta']) {
            return $f;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
//  Argumentos
// ---------------------------------------------------------------------------

$args   = array_slice($argv, 1);
$conSii = in_array('--con-sii', $args, true);
$args   = array_values(array_filter($args, static fn (string $a): bool => $a !== '--con-sii'));

if (count($args) !== 2) {
    abortar("uso: php scripts/verificar_nota_credito.php <ambiente> <rut_emisor> [--con-sii]");
}
[$ambienteArg, $rut] = $args;
$ambiente = match ($ambienteArg) {
    'certificacion' => Ambiente::Certificacion,
    'produccion'    => Ambiente::Produccion,
    default         => abortar("ambiente debe ser 'certificacion' o 'produccion'."),
};

$hex = requerirEnv('CRYPTO_MASTER_KEY');
$bin = @hex2bin($hex);
if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
    abortar('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 caracteres).');
}

$pdo    = conectarDb();
$crypto = new CertificadoCrypto($bin);
// cryptoKek va SI O SI: los CAF se guardan con cifrado por sobre (una DEK por
// CAF, envuelta con la master key). Sin pasar la KEK, descifrarCaf() toma el
// camino antiguo y falla con "tag no valido", que parece un CAF corrupto y no
// lo es. Se arma igual que crearFacturador() en public/index.php: si las dos
// fabricas divergen, este arnes deja de verificar lo que produccion hace.
$folios = new MySqlFolioRepository(
    $pdo,
    static fn (string $c): string => $crypto->descifrar($c),
    cryptoKek: $crypto,
);
$emisor = new MySqlEmisorRepository($pdo, $crypto);

echo "\nVerificacion de emision de NOTA DE CREDITO (61)\n";
echo "  emisor:   {$rut}\n";
echo "  ambiente: {$ambienteArg}\n";
echo "  NO se asignan folios, NO se envia nada al SII"
    . ($conSii ? " salvo la autenticacion (lectura).\n" : ".\n");

// ---------------------------------------------------------------------------
pantalla('PANTALLA 1 -- Contador de folios de tipo 61');
// ---------------------------------------------------------------------------

$contadorAntes = leerContador($pdo, $rut, 61, $ambienteArg);
if ($contadorAntes === null) {
    falla('no hay CAF activo de tipo 61 con folios disponibles. Sin esto no se puede emitir ninguna NC.');
    dato('Cargar el CAF en el panel: Configuracion > Folios y CAF.');
    echo "\nVEREDICTO: NO OPERATIVA (faltan folios de tipo 61).\n";
    exit(1);
}

$proximo   = (int) $contadorAntes['proximo_folio'];
$hasta     = (int) $contadorAntes['folio_hasta'];
$restantes = $hasta - $proximo + 1;
ok("CAF activo #{$contadorAntes['caf_id']}, rango {$contadorAntes['folio_desde']}..{$hasta}.");
ok("quedan {$restantes} folio(s); el proximo en salir seria el {$proximo}.");
if ($restantes <= 2) {
    dato("AVISO: con {$restantes} folio(s) el margen es minimo. Conviene pedir un CAF nuevo al SII.");
}

// Coherencia del contador. Un contador POR DEBAJO de lo ya emitido volveria a
// entregar un folio usado: el SII lo rechazaria por folio repetido y ademas
// chocaria con el UNIQUE de dte_emitido. Se mira contra las dos fuentes.
$q = $pdo->prepare('SELECT MAX(folio) FROM dte_emitido WHERE rut_emisor=? AND ambiente=? AND tipo_dte=61');
$q->execute([$rut, $ambienteArg]);
$maxEmitido = (int) ($q->fetchColumn() ?: 0);

$q = $pdo->prepare('SELECT MAX(folio) FROM dte_folio_log WHERE rut_emisor=? AND ambiente=? AND tipo_dte=61');
$q->execute([$rut, $ambienteArg]);
$maxEntregado = (int) ($q->fetchColumn() ?: 0);

if ($maxEmitido === 0 && $maxEntregado === 0) {
    ok('nunca se ha emitido una NC con este emisor: no hay historial que contradiga al contador.');
    dato('Es justamente el caso que este arnes viene a cubrir: el camino no se ha ejercido en produccion.');
} else {
    dato("maximo folio 61 emitido: {$maxEmitido} · maximo entregado por el contador: {$maxEntregado}");
    if ($proximo <= $maxEntregado) {
        falla("el contador ({$proximo}) NO supera al ultimo folio entregado ({$maxEntregado}): volveria a entregar un folio usado.");
    } else {
        ok('el contador va por delante de todo lo entregado.');
    }
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 2 -- CAF de tipo 61');
// ---------------------------------------------------------------------------

try {
    $cafXml = $folios->obtenerCafActivo($rut, TipoDte::NotaCreditoElectronica, $ambiente);
    ok('el CAF descifra con la CRYPTO_MASTER_KEY del entorno (' . strlen($cafXml) . ' bytes).');
} catch (Throwable $e) {
    falla('no se pudo obtener/descifrar el CAF: ' . $e->getMessage());
    echo "\nVEREDICTO: NO OPERATIVA.\n";
    exit(1);
}

$caf = new DOMDocument();
if (@$caf->loadXML($cafXml) === false) {
    falla('el CAF descifrado no es XML valido.');
} else {
    $texto = static function (string $tag) use ($caf): string {
        $n = $caf->getElementsByTagName($tag)->item(0);
        return $n === null ? '' : trim((string) $n->textContent);
    };
    $rutCaf  = $texto('RE');
    $tipoCaf = $texto('TD');
    $desde   = (int) $texto('D');
    $ultimo  = (int) $texto('H');

    $rutCaf === $rut
        ? ok("el CAF es del emisor correcto ({$rutCaf}).")
        : falla("el CAF es de OTRO emisor: dice {$rutCaf}, se esperaba {$rut}.");

    $tipoCaf === '61'
        ? ok('el CAF es de tipo 61 (nota de credito).')
        : falla("el CAF es de tipo {$tipoCaf}, no 61.");

    ($desde === (int) $contadorAntes['folio_desde'] && $ultimo === $hasta)
        ? ok("el rango del CAF ({$desde}..{$ultimo}) coincide con el contador.")
        : falla("el rango del CAF ({$desde}..{$ultimo}) NO coincide con el contador "
            . "({$contadorAntes['folio_desde']}..{$hasta}): el TED se timbraria fuera de rango.");

    ($proximo >= $desde && $proximo <= $ultimo)
        ? ok("el folio a usar ({$proximo}) cae dentro del rango autorizado.")
        : falla("el folio a usar ({$proximo}) queda FUERA del rango del CAF.");

    // La llave privada del CAF es la que timbra el TED. Si no carga, la NC se
    // construye pero el timbre sale invalido y el SII la rechaza.
    $rsask = $texto('RSASK');
    if ($rsask === '') {
        falla('el CAF no trae RSASK (llave privada): sin ella no hay timbre.');
    } elseif (@openssl_pkey_get_private($rsask) === false) {
        falla('la llave privada del CAF no carga: ' . (openssl_error_string() ?: 'motivo desconocido'));
    } else {
        ok('la llave privada del CAF carga y puede timbrar.');
    }
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 3 -- Certificado digital');
// ---------------------------------------------------------------------------

try {
    $cert = $emisor->obtenerCertificado($rut, $ambiente);
    ok('el certificado descifra y se carga.');
} catch (Throwable $e) {
    falla('no se pudo obtener el certificado: ' . $e->getMessage());
    echo "\nVEREDICTO: NO OPERATIVA.\n";
    exit(1);
}

$parsed = @openssl_x509_parse($cert->certData);
if (! is_array($parsed)) {
    falla('el certificado no se puede parsear.');
    $rutSender = $rut;
} else {
    $hasta509 = (int) ($parsed['validTo_time_t'] ?? 0);
    $dias     = (int) floor(($hasta509 - time()) / 86400);
    dato('titular: ' . ($parsed['subject']['CN'] ?? '?'));
    dato('vence:   ' . gmdate('Y-m-d', $hasta509) . " ({$dias} dias)");
    if ($hasta509 <= time()) {
        falla('el certificado esta VENCIDO: no puede firmar ningun DTE.');
    } elseif ($dias <= 30) {
        ok('el certificado esta vigente.');
        dato("AVISO: vence en {$dias} dias. Renovarlo antes de que corte la emision.");
    } else {
        ok('el certificado esta vigente con margen.');
    }
    // EL rutSender SE LEE DE dte_certificado, que es de donde lo saca el motor
    // (resolverRutSender()). Deducirlo aqui del serialNumber del certificado
    // seria verificar un valor que produccion no usa: el que se guardo paso por
    // CertificadoRutSenderExtractor, que lo reconstruye desde digitos+K, y ahi
    // puede haber diferencias con el texto crudo del campo.
    $q = $pdo->prepare('SELECT rut_sender FROM dte_certificado WHERE rut_emisor = ? AND ambiente = ? LIMIT 1');
    $q->execute([$rut, $ambienteArg]);
    $rutSender = trim((string) ($q->fetchColumn() ?: ''));
    if ($rutSender === '') {
        falla('el certificado no tiene rut_sender guardado: el motor respondería 409 al emitir.');
        $sn        = $parsed['subject']['serialNumber'] ?? null;
        $rutSender = is_string($sn) && $sn !== '' ? Rut::normalizar($sn) : $rut;
        dato("se seguira la prueba con {$rutSender}, deducido del certificado.");
    } else {
        ok("rutSender (firmante) guardado: {$rutSender}");
        Rut::bienFormado($rutSender)
            ? ok('el rutSender esta bien escrito para el SII.')
            : falla("el rutSender '{$rutSender}' no cumple el patron del esquema: la caratula saldria rechazada.");
    }
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 4 -- Datos del emisor (caratula)');
// ---------------------------------------------------------------------------

try {
    $datos = $emisor->obtenerDatosEmisor($rut, $ambiente);
    ok('los datos del emisor estan cargados.');
    dato("razon social: {$datos->razonSocial}");
    dato("giro:         {$datos->giro}");
    dato("direccion:    {$datos->dirOrigen}, {$datos->cmnaOrigen}");
    dato("acteco:       {$datos->acteco}");
    // Los nombres son los del DTO (dirOrigen/cmnaOrigen), que a su vez son los
    // de la caratula del SII. No inventar alias: si el DTO cambia, esto tiene
    // que romper aqui y no silenciarse.
    foreach (['razonSocial' => $datos->razonSocial, 'giro' => $datos->giro,
              'dirOrigen' => $datos->dirOrigen, 'cmnaOrigen' => $datos->cmnaOrigen] as $campo => $valor) {
        if (trim((string) $valor) === '') {
            falla("el campo '{$campo}' esta vacio: la caratula del EnvioDTE lo exige.");
        }
    }
} catch (Throwable $e) {
    falla('no se pudieron obtener los datos del emisor: ' . $e->getMessage());
    echo "\nVEREDICTO: NO OPERATIVA.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 5 -- Documento al que referenciar');
// ---------------------------------------------------------------------------

// Una NC SIN referencia la rechaza el SII (REF-3-415), y el motor la rechaza
// antes con un 422. Se busca un documento REAL del emisor para que la prueba
// use la misma forma que usaria una NC de verdad.
$q = $pdo->prepare(
    'SELECT tipo_dte, folio, fecha_emision, receptor_rut, total '
    . 'FROM dte_emitido WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte IN (33,34,39) '
    . 'ORDER BY id DESC LIMIT 1'
);
$q->execute([$rut, $ambienteArg]);
$original = $q->fetch(PDO::FETCH_ASSOC);

if ($original === false) {
    falla('el emisor no tiene ningun documento emitido al que una NC pueda referenciar.');
    dato('No es un fallo del codigo: sin documento madre no hay nota de credito que emitir.');
    echo "\nVEREDICTO: NO SE PUEDE VERIFICAR (no hay documento de referencia).\n";
    exit(1);
}

ok(sprintf(
    'se referenciara el tipo %d folio %d del %s (receptor %s).',
    $original['tipo_dte'],
    $original['folio'],
    $original['fecha_emision'],
    $original['receptor_rut'],
));

// ---------------------------------------------------------------------------
pantalla('PANTALLA 5b -- Que paso con las NC realmente emitidas');
// ---------------------------------------------------------------------------

// ESTA PANTALLA EXISTE PORQUE LAS DEMAS NO BASTAN, y se aprendio por las malas.
//
// El resto del arnes construye una NC con datos que saca de la BASE, ya
// canonicos. Eso verifica la MAQUINARIA, pero no lo que el usuario teclea: el
// 02-09-2026 la maquinaria daba OK en todo y la NC real (folio 5) la habia
// rechazado el SII con RSC "Error en Schema", porque el RUT del receptor viajo
// con puntos desde el formulario. Un arnes que solo se prueba a si mismo dice
// que todo esta bien mientras produccion falla.
//
// Por eso aqui se mira el XML QUE DE VERDAD SE ENVIO, tal como quedo guardado.
$q = $pdo->prepare(
    'SELECT folio, estado, track_id, created_at, xml FROM dte_emitido '
    . 'WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte = 61 ORDER BY id DESC LIMIT 3'
);
$q->execute([$rut, $ambienteArg]);
$ncEmitidas = $q->fetchAll(PDO::FETCH_ASSOC);

if ($ncEmitidas === []) {
    dato('todavia no se ha emitido ninguna NC con este emisor: no hay historial que revisar.');
} elseif (! is_file(XSD)) {
    falla('no se encuentra el esquema en ' . XSD . ': no se puede revisar el historial.');
} else {
    foreach ($ncEmitidas as $nc) {
        dato("--- folio {$nc['folio']} · estado '{$nc['estado']}' · trackid {$nc['track_id']} · {$nc['created_at']}");

        // RSC = "Rechazado por Error en Schema". Es el estado que delata un XML
        // mal formado ANTES de cualquier regla tributaria, y el folio ya se gasto.
        if ($nc['estado'] === 'RSC') {
            falla("la NC folio {$nc['folio']} fue RECHAZADA por el SII (RSC: error de schema) y su folio se gasto.");
            dato('    Ese documento NO existe para el SII: hay que volver a emitirlo con un folio nuevo.');
            dato('    Seguira apareciendo aqui en rojo mientras siga rechazado; es historia, no una averia de hoy.');
        }

        $previo = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $enviado = new DOMDocument();
        if (@$enviado->loadXML((string) $nc['xml']) === false) {
            falla("el XML guardado de la NC folio {$nc['folio']} no se puede parsear.");
        } elseif ($enviado->schemaValidate(XSD)) {
            ok("el XML enviado de la NC folio {$nc['folio']} valida contra el esquema del SII.");
        } else {
            falla("el XML enviado de la NC folio {$nc['folio']} NO valida contra el esquema del SII:");
            foreach (array_slice(libxml_get_errors(), 0, 6) as $err) {
                dato('    ' . trim($err->message));
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
    }
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 6 -- Construccion y firma de la NC (folio LEIDO, no tomado)');
// ---------------------------------------------------------------------------

$doc = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: new Receptor(
        rut: (string) $original['receptor_rut'],
        razonSocial: 'RECEPTOR DEL DOCUMENTO ORIGINAL',
        giro: 'Servicios',
        direccion: 'Sin direccion',
        comuna: 'Santiago',
    ),
    detalles: [new Detalle('Anulacion documento de referencia', 1, (int) $original['total'])],
    montosSonBrutos: true,
    referencias: [[
        'tipoDocumento' => (int) $original['tipo_dte'],
        'folio'         => (int) $original['folio'],
        'fecha'         => (string) $original['fecha_emision'],
        // CodRef 1 = anula el documento de referencia. Es el caso mas comun y el
        // que mas exige del XML: el SII cruza montos y referencia.
        'codigo'        => 1,
        'razon'         => 'Verificacion tecnica -- este documento NO se envia',
    ]],
);

$signer       = new XmlSigner();
$dteBuilder   = new DteXmlBuilder();
$tedBuilder   = new TedBuilder();
$envioBuilder = new EnvioDteBuilder();

$primerElemento = static function (DOMDocument $d, string $tag): DOMElement {
    $n = $d->getElementsByTagNameNS(NS_SII, $tag)->item(0);
    if (! $n instanceof DOMElement) {
        abortar("no se encontro <{$tag}> en el XML generado.");
    }
    return $n;
};

try {
    // Este bloque es una COPIA FIEL de los puntos 3 a 6 de
    // SiiDirectoFacturador::emitir(), en el mismo orden y con las mismas
    // llamadas. Es lo que hace que el resultado valga: si aqui se usara el
    // camino corto (firmarNodo directo, como probar_emision_local.php), se
    // estaria verificando un camino que produccion no recorre.
    $dteDoc   = $dteBuilder->build($doc, $datos, $proximo);
    $envioDoc = $envioBuilder->build([$dteDoc], $datos, RUT_SII, $rutSender, $ambiente);
    $tedBuilder->build($envioDoc, $doc, $datos, $proximo, $cafXml);

    $idsDocumentos = [];
    foreach ($envioDoc->getElementsByTagNameNS(NS_SII, 'Documento') as $documento) {
        if ($documento instanceof DOMElement) {
            $id = $documento->getAttribute('ID');
            $signer->insertarEsqueletoFirma($documento, $id, $cert);
            $idsDocumentos[] = $id;
        }
    }
    if ($idsDocumentos === []) {
        abortar('el envio generado no contiene ningun <Documento>.');
    }
    $signer->insertarEsqueletoFirma($primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);
    $envioBuilder->agregarSchemaLocation($envioDoc);
    $signer->congelar($envioDoc);
    foreach ($idsDocumentos as $idDoc) {
        $signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
    }
    $signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

    $xml = (string) $envioDoc->saveXML();
    ok('la NC se construyo, se timbro (TED) y se firmo sin errores.');
    dato('ID Documento: ' . implode(', ', $idsDocumentos) . ' · ID SetDTE: SetDoc');
    dato('tamano del EnvioDTE: ' . strlen($xml) . ' bytes');
} catch (Throwable $e) {
    falla('no se pudo construir/firmar la NC: ' . $e->getMessage());
    dato('en ' . $e->getFile() . ':' . $e->getLine());
    $xml = null;
}

// El TED tiene que llevar el folio que se iba a usar, y las dos firmas tienen
// que existir. Es la comprobacion barata; la verificacion criptografica fina la
// hace scripts/verificar_firma_sii.php sobre el archivo que se deja abajo.
if ($xml !== null) {
    $ted = $envioDoc->getElementsByTagName('TED')->item(0);
    $ted === null
        ? falla('el documento no lleva TED: sin timbre el SII lo rechaza.')
        : ok('el TED esta presente.');

    $firmas = $envioDoc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length;
    $firmas >= 2
        ? ok("hay {$firmas} firmas (Documento + SetDTE), como exige el SII.")
        : falla("solo hay {$firmas} firma(s); se esperaban al menos 2 (Documento y SetDTE).");

    $folioEnXml = $envioDoc->getElementsByTagNameNS(NS_SII, 'Folio')->item(0);
    ($folioEnXml !== null && (int) $folioEnXml->textContent === $proximo)
        ? ok("el XML lleva el folio {$proximo}, el que se habria usado.")
        : falla('el folio del XML no coincide con el leido del contador.');

    $ruta = sys_get_temp_dir() . "/nc_verificacion_{$rut}_{$proximo}.xml";
    file_put_contents($ruta, $xml);
    dato("XML dejado en {$ruta} (NO enviado). Para la firma:");
    dato("  php scripts/verificar_firma_sii.php {$ruta}");
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 7 -- Validacion contra el XSD oficial del SII');
// ---------------------------------------------------------------------------

if ($xml === null) {
    falla('no hay XML que validar (fallo la construccion).');
} elseif (! is_file(XSD)) {
    falla('no se encuentra el esquema en ' . XSD);
} else {
    $previo = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $paraValidar = new DOMDocument();
    $paraValidar->loadXML($xml);
    if ($paraValidar->schemaValidate(XSD)) {
        ok('el EnvioDTE valida contra EnvioDTE_v10.xsd, el esquema del propio SII.');
    } else {
        falla('el EnvioDTE NO valida contra el esquema del SII:');
        foreach (array_slice(libxml_get_errors(), 0, 8) as $err) {
            dato('  linea ' . $err->line . ': ' . trim($err->message));
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previo);
}

// ---------------------------------------------------------------------------
if ($conSii) {
    pantalla('PANTALLA 7b -- Autenticacion contra el SII (lectura, no consume folios)');

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    if (! is_readable($certTls) || ! is_readable($keyTls)) {
        falla('faltan fullchain.pem / key.pem para el TLS mutuo con el SII.');
    } else {
        try {
            $http  = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
            $token = (new SiiAutenticador($http, $signer))->obtenerToken($cert, $ambiente);
            $token !== ''
                ? ok('el SII entrego un token: el certificado sirve HOY contra el SII (' . strlen($token) . ' bytes).')
                : falla('el SII devolvio un token vacio.');
        } catch (Throwable $e) {
            falla('no se pudo autenticar contra el SII: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
pantalla('PANTALLA 8 -- El contador de folios sigue intacto');
// ---------------------------------------------------------------------------

$contadorDespues = leerContador($pdo, $rut, 61, $ambienteArg);
$despues         = $contadorDespues === null ? null : (int) $contadorDespues['proximo_folio'];

if ($despues === $proximo) {
    ok("proximo_folio sigue en {$proximo}: no se gasto ningun folio.");
} else {
    falla("EL CONTADOR SE MOVIO: estaba en {$proximo} y ahora esta en "
        . ($despues === null ? 'sin CAF activo' : (string) $despues) . '.');
    dato('Algo en este arnes consumio un folio. Revisar antes de volver a correrlo.');
}

$q = $pdo->prepare('SELECT COUNT(*) FROM dte_emitido WHERE rut_emisor=? AND ambiente=? AND tipo_dte=61');
$q->execute([$rut, $ambienteArg]);
$nc = (int) $q->fetchColumn();
ok("notas de credito en dte_emitido: {$nc} (este arnes no agrega ninguna).");

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
if ($fallos === 0) {
    echo "  VEREDICTO: la emision de notas de credito esta OPERATIVA.\n";
    echo "  Se construyo, timbro, firmo y valido una NC contra el esquema del SII, y las\n";
    echo "  NC ya enviadas tampoco tienen reparos. Lo unico que no se ejercio es el envio,\n";
    echo "  que es lo que gasta el folio.\n";
    echo str_repeat('=', 78) . "\n";
    exit(0);
}

echo "  VEREDICTO: {$fallos} comprobacion(es) fallaron. Ver arriba.\n";
echo str_repeat('=', 78) . "\n";
exit(1);
