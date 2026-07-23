<?php
// Verificador SII-fiel: comprueba DigestValue y SignatureValue de cada nodo tal
// como los valida el SII. El <Documento> se verifica como standalone (sin el xsi
// del sobre); el <SetDTE> dentro del sobre (con xsi). No consume folio ni envia.
// Uso: php scripts/verificar_firma_sii.php /app/dte_diagnostico.xml

$path = $argv[1] ?? '/app/dte_diagnostico.xml';
$d = new DOMDocument();
if ($d->load($path) === false) {
    fwrite(STDERR, "No se pudo cargar $path\n");
    exit(1);
}

function limpiar(string $x): string {
    $x = preg_replace('/ xmlns:default\d*="http:\/\/www\.w3\.org\/2000\/09\/xmldsig#"/', '', $x);
    $x = preg_replace('/<(\/?)default\d*:/', '<$1', $x);
    return $x;
}
function quitarXsi(string $x): string {
    $x = preg_replace('/ xmlns:xsi="[^"]*"/', '', $x, 1);
    $x = preg_replace('/ xsi:schemaLocation="[^"]*"/', '', $x, 1);
    return $x;
}
function nodeById(DOMDocument $d, string $id): ?DOMElement {
    foreach ($d->getElementsByTagName('*') as $el) {
        if ($el instanceof DOMElement && $el->getAttribute('ID') === $id) {
            return $el;
        }
    }
    return null;
}
function sigByUri(DOMDocument $d, string $uri): ?DOMElement {
    foreach ($d->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature') as $s) {
        $r = $s->getElementsByTagName('Reference')->item(0);
        if ($r instanceof DOMElement && $r->getAttribute('URI') === $uri) {
            return $s;
        }
    }
    return null;
}

$b64 = preg_replace('/\s+/', '', $d->getElementsByTagName('X509Certificate')->item(0)->textContent);
$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
$pub = openssl_pkey_get_public($pem);
if ($pub === false) {
    fwrite(STDERR, "Cert invalido\n");
    exit(1);
}

function verifica(DOMDocument $d, $pub, string $uri, string $tag, bool $excluirXsi): void {
    $sig = sigByUri($d, $uri);
    $sd = $sig->getElementsByTagName('DigestValue')->item(0)->textContent;
    $ss = preg_replace('/\s+/', '', $sig->getElementsByTagName('SignatureValue')->item(0)->textContent);

    $xml = limpiar($d->saveXML());
    if ($excluirXsi) {
        $xml = quitarXsi($xml);
    }
    $w = new DOMDocument();
    $w->loadXML($xml);
    $id = ltrim($uri, '#');

    $okD = base64_encode(sha1(nodeById($w, $id)->C14N(), true)) === $sd;
    $si = sigByUri($w, $uri)->getElementsByTagName('SignedInfo')->item(0);
    $okS = openssl_verify($si->C14N(), base64_decode($ss), $pub, OPENSSL_ALGO_SHA1) === 1;

    echo "[$tag]  digest " . ($okD ? 'OK' : 'FALLA') . "   firma " . ($okS ? 'OK' : 'FALLA') . "\n";
}

$docId = $d->getElementsByTagName('Documento')->item(0)->getAttribute('ID');
echo "== Verificacion SII-fiel ==\n";
verifica($d, $pub, '#' . $docId, 'DOCUMENTO standalone (sin xsi)', true);
verifica($d, $pub, '#SetDoc', 'SETDTE en sobre (con xsi)', false);
