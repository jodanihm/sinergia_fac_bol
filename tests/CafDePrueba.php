<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

/**
 * Helper de tests (NO es un TestCase): genera un CAF XML estructuralmente
 * valido con un par RSA real, para ejercitar TedBuilder y el flujo de emision.
 *
 * El CAF incluye RSASK (privada, para firmar el TED) y RSAPUBK (publica, para
 * verificar el FRMT en los tests). La firma FRMA del CAF es ficticia.
 */
final class CafDePrueba
{
    /**
     * @return array{xml: string, rsapubk: string, rsask: string}|null
     *         null si el entorno no puede generar llaves (falta openssl.cnf).
     */
    public static function generar(
        string $rut = '77724622-4',
        int $tipo = 33,
        int $desde = 1,
        int $hasta = 100
    ): ?array {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            return null;
        }
        openssl_pkey_export($pkey, $rsask);
        $details = openssl_pkey_get_details($pkey);
        if ($details === false) {
            return null;
        }
        $rsapubk = (string) $details['key'];
        $m = base64_encode((string) $details['rsa']['n']);
        $e = base64_encode((string) $details['rsa']['e']);

        $xml = '<?xml version="1.0"?>'
            . '<AUTORIZACION>'
            . '<CAF version="1.0">'
            . '<DA>'
            . '<RE>' . $rut . '</RE>'
            . '<RS>RAZON SOCIAL DE PRUEBA SPA</RS>'
            . '<TD>' . $tipo . '</TD>'
            . '<RNG><D>' . $desde . '</D><H>' . $hasta . '</H></RNG>'
            . '<FA>2026-05-28</FA>'
            . '<RSAPK><M>' . $m . '</M><E>' . $e . '</E></RSAPK>'
            . '<IDK>100</IDK>'
            . '</DA>'
            . '<FRMA algoritmo="SHA1withRSA">FAKE_SIGNATURE_PRUEBA</FRMA>'
            . '</CAF>'
            . '<RSASK>' . $rsask . '</RSASK>'
            . '<RSAPUBK>' . $rsapubk . '</RSAPUBK>'
            . '</AUTORIZACION>';

        return ['xml' => $xml, 'rsapubk' => $rsapubk, 'rsask' => $rsask];
    }
}
