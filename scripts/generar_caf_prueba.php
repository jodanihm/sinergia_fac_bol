<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ESTE CAF ES FICTICIO. NO LO USES PARA EMITIR AL SII.
 * ============================================================================
 *  Genera un CAF.xml con la estructura correcta pero SIN firma valida del SII
 *  (el campo <FRMA> es un placeholder). Sirve SOLO para probar en local:
 *    - la carga del CAF (scripts/cargar_caf.php),
 *    - el cifrado AES-256-GCM,
 *    - la asignacion atomica de folios,
 *    - la construccion del XML del DTE.
 *  El SII rechazaria cualquier DTE emitido con este CAF: la firma FRMA es
 *  invalida y las llaves no son las autorizadas por el SII.
 * ============================================================================
 *
 * USO:
 *   php scripts/generar_caf_prueba.php <ambiente> <rut> <tipoDte> <folioDesde> <folioHasta> <rutaSalida>
 *
 * EJEMPLO:
 *   php scripts/generar_caf_prueba.php certificacion 77724622-4 33 1 10 ./CAF_prueba_F33.xml
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

$args = array_slice($argv, 1);
if (count($args) !== 6) {
    fail(
        "Uso: php scripts/generar_caf_prueba.php <ambiente> <rut> <tipoDte> "
        . "<folioDesde> <folioHasta> <rutaSalida>"
    );
}
[$ambiente, $rut, $tipoDte, $folioDesde, $folioHasta, $rutaSalida] = $args;

if (! in_array($ambiente, ['certificacion', 'produccion'], true)) {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
foreach (['tipoDte' => $tipoDte, 'folioDesde' => $folioDesde, 'folioHasta' => $folioHasta] as $n => $v) {
    if (! ctype_digit($v)) {
        fail("{$n} debe ser numerico (recibido: '{$v}').");
    }
}
$tipo  = (int) $tipoDte;
$desde = (int) $folioDesde;
$hasta = (int) $folioHasta;
if ($hasta < $desde) {
    fail("folioHasta ({$hasta}) no puede ser menor que folioDesde ({$desde}).");
}

// Generar un par RSA real para que M/E/RSASK/RSAPUBK sean estructuralmente
// validos (aunque NO sean las llaves autorizadas por el SII).
$pkey = openssl_pkey_new([
    'private_key_bits' => 1024, // chico a proposito: es de prueba, no produccion.
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($pkey === false) {
    fail('openssl_pkey_new fallo (revisa openssl.cnf / extension openssl).');
}
openssl_pkey_export($pkey, $rsask);
$details = openssl_pkey_get_details($pkey);
if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'], $details['key'])) {
    fail('No se pudieron extraer detalles de la llave RSA.');
}
$modulusB64  = base64_encode((string) $details['rsa']['n']);
$exponentB64 = base64_encode((string) $details['rsa']['e']);
$rsapubk     = (string) $details['key'];

$fecha = date('Y-m-d');

$xml = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<AUTORIZACION>
  <CAF version="1.0">
    <DA>
      <RE>{$rut}</RE>
      <RS>RAZON SOCIAL DE PRUEBA SPA</RS>
      <TD>{$tipo}</TD>
      <RNG><D>{$desde}</D><H>{$hasta}</H></RNG>
      <FA>{$fecha}</FA>
      <RSAPK><M>{$modulusB64}</M><E>{$exponentB64}</E></RSAPK>
      <IDK>100</IDK>
    </DA>
    <FRMA algoritmo="SHA1withRSA">FAKE_SIGNATURE_PARA_PRUEBA_LOCAL_NO_VALIDA_ANTE_SII</FRMA>
  </CAF>
  <RSASK>{$rsask}</RSASK>
  <RSAPUBK>{$rsapubk}</RSAPUBK>
</AUTORIZACION>
XML;

if (file_put_contents($rutaSalida, $xml) === false) {
    fail("No se pudo escribir el archivo: {$rutaSalida}");
}

echo "CAF FICTICIO generado (NO valido ante el SII): {$rutaSalida}\n";
echo "  ambiente={$ambiente} rut={$rut} tipo={$tipo} rango={$desde}-{$hasta}\n";
echo "  Cargalo con: php scripts/cargar_caf.php {$rutaSalida} {$ambiente} {$rut}\n";
