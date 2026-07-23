<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

/**
 * Extrae, de forma tolerante, el RUT del FIRMANTE (rut_sender) de un
 * certificado digital SII (PEM). Dos metodos, en orden:
 *
 *   1. subject.serialNumber (via openssl_x509_parse()) -- metodo historico,
 *      ya probado en produccion. Cubre la mayoria de los certificados SII.
 *   2. Fallback (solo si el 1 no encuentra nada): extension subjectAltName,
 *      buscando un otherName con el OID oficial chileno de RUN/RUT
 *      (1.3.6.1.4.1.8321.1). openssl_x509_parse() NO expone el valor de un
 *      otherName con OID no reconocido por OpenSSL (solo
 *      "othername:<unsupported>" en el texto de la extension), asi que este
 *      metodo parsea el DER de la extension a mano -- SIN shell-out a un
 *      binario externo ni dependencias nuevas, solo bytes + aritmetica.
 *
 * Ambos metodos devuelven el mismo formato normalizado (NNNNNNNN-DV, sin
 * puntos ni espacios) o null si no se pudo extraer nada aceptable -- NUNCA
 * lanza: cualquier certificado mal formado o extension ausente/rara
 * simplemente no aporta un RUT (rut_sender queda null, no bloquea la subida
 * del certificado).
 */
final class CertificadoRutSenderExtractor
{
    private const OID_RUT_CHILE = '1.3.6.1.4.1.8321.1';
    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';

    /** Tags DER de tipos "string" aceptables para el valor del otherName. */
    private const TAGS_STRING = [0x0C, 0x13, 0x16, 0x14]; // UTF8String, PrintableString, IA5String, T61String

    public static function extraer(string $certPem): ?string
    {
        $porSerialNumber = self::porSerialNumber($certPem);
        if ($porSerialNumber !== null) {
            return $porSerialNumber;
        }

        return self::porSubjectAltName($certPem);
    }

    private static function porSerialNumber(string $certPem): ?string
    {
        $parsed = openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return null;
        }

        $sn = $parsed['subject']['serialNumber'] ?? null;
        if (! is_string($sn) || trim($sn) === '') {
            return null;
        }

        return self::normalizarCandidato($sn);
    }

    private static function porSubjectAltName(string $certPem): ?string
    {
        try {
            $valor = self::buscarOtherNameEnSan(self::pemADer($certPem), self::OID_RUT_CHILE);
        } catch (\Throwable $e) {
            return null;
        }

        return $valor === null ? null : self::normalizarCandidato($valor);
    }

    /**
     * Normaliza un candidato de RUT en texto libre (con o sin puntos/guion/
     * prefijos) al formato NNNNNNNN-DV. Mismo criterio ya usado para el
     * serialNumber: no exige digito verificador correcto (Rut::formaValida),
     * solo que tenga FORMA de RUT -- el texto libre de un certificado no
     * sigue un formato estandar. Duplica la micro-logica de
     * panel/src/Rut.php (normalizar/formaValida) a proposito: esa clase es
     * global/no-namespaced y vive fuera del autoload de Composer, esta clase
     * no depende de ella para quedar autocontenida y testeable.
     */
    private static function normalizarCandidato(string $candidato): ?string
    {
        $normalizado = strtoupper(str_replace(['.', ' '], '', trim($candidato)));
        $soloRut     = preg_replace('/[^0-9K]/', '', $normalizado);
        if (! is_string($soloRut) || ! preg_match('/^\d{6,8}[0-9K]$/', $soloRut)) {
            return null;
        }

        return substr($soloRut, 0, -1) . '-' . substr($soloRut, -1);
    }

    private static function pemADer(string $pem): string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
            throw new \RuntimeException('PEM invalido: no se encontro el bloque CERTIFICATE');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === false) {
            throw new \RuntimeException('No se pudo decodificar base64 del PEM');
        }

        return $der;
    }

    /**
     * Busca, dentro del DER de un certificado X.509, la extension
     * subjectAltName y devuelve el valor de texto del PRIMER otherName cuyo
     * type-id sea $oid. Recorre a mano: Certificate -> tbsCertificate ->
     * extensions [3] -> Extension SEQUENCE OF -> extnValue (OCTET STRING con
     * GeneralNames DER adentro) -> GeneralName [0] otherName -> OtherName.
     */
    private static function buscarOtherNameEnSan(string $der, string $oid): ?string
    {
        $certificate    = self::leerTlv($der, 0);
        $tbsCertificate = self::leerTlv($certificate['content'], 0);
        $camposTbs      = self::leerHijos($tbsCertificate['content']);

        $extensionesExplicit = null;
        foreach ($camposTbs as $campo) {
            if ($campo['tag'] === 0xA3) { // [3] EXPLICIT Extensions
                $extensionesExplicit = $campo;
                break;
            }
        }
        if ($extensionesExplicit === null) {
            return null;
        }

        $extensionsSeq = self::leerTlv($extensionesExplicit['content'], 0);
        foreach (self::leerHijos($extensionsSeq['content']) as $extension) {
            // Extension ::= SEQUENCE { extnID OID, critical BOOLEAN OPTIONAL, extnValue OCTET STRING }
            $partes = self::leerHijos($extension['content']);
            if ($partes === [] || $partes[0]['tag'] !== 0x06) {
                continue;
            }
            if (self::decodificarOid($partes[0]['content']) !== self::OID_SUBJECT_ALT_NAME) {
                continue;
            }

            $extnValue = end($partes);
            if ($extnValue === false || $extnValue['tag'] !== 0x04) {
                continue;
            }

            $generalNamesSeq = self::leerTlv($extnValue['content'], 0);
            foreach (self::leerHijos($generalNamesSeq['content']) as $generalName) {
                if ($generalName['tag'] !== 0xA0) { // [0] otherName
                    continue;
                }
                $valor = self::valorSiOtherNameCoincide($generalName['content'], $oid);
                if ($valor !== null) {
                    return $valor;
                }
            }
        }

        return null;
    }

    private static function valorSiOtherNameCoincide(string $otherNameContent, string $oid): ?string
    {
        // OtherName ::= SEQUENCE { type-id OID, value [0] EXPLICIT ANY DEFINED BY type-id }
        $campos = self::leerHijos($otherNameContent);
        if (count($campos) < 2 || $campos[0]['tag'] !== 0x06) {
            return null;
        }
        if (self::decodificarOid($campos[0]['content']) !== $oid) {
            return null;
        }

        // $campos[1] es el wrapper [0] EXPLICIT: su contenido es el TLV completo del tipo real.
        $valorTlv = self::leerTlv($campos[1]['content'], 0);
        if (! in_array($valorTlv['tag'], self::TAGS_STRING, true)) {
            return null;
        }

        return $valorTlv['content'];
    }

    /**
     * Lee UNA TLV (tag-length-value) DER a partir de $offset. Soporta
     * longitud corta y larga (hasta 4 bytes de longitud); NO soporta
     * longitud indefinida (no aplica a DER, solo a BER).
     *
     * @return array{tag:int, length:int, headerLength:int, content:string}
     */
    private static function leerTlv(string $der, int $offset): array
    {
        $len = strlen($der);
        if ($offset >= $len) {
            throw new \RuntimeException('DER truncado (tag)');
        }
        $tag = ord($der[$offset]);
        $pos = $offset + 1;
        if ($pos >= $len) {
            throw new \RuntimeException('DER truncado (longitud)');
        }

        $primerByteLongitud = ord($der[$pos]);
        $pos++;
        if ($primerByteLongitud < 0x80) {
            $longitud = $primerByteLongitud;
        } else {
            $numBytes = $primerByteLongitud & 0x7F;
            if ($numBytes === 0 || $numBytes > 4 || $pos + $numBytes > $len) {
                throw new \RuntimeException('Longitud DER no soportada o truncada');
            }
            $longitud = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $longitud = ($longitud << 8) | ord($der[$pos + $i]);
            }
            $pos += $numBytes;
        }

        if ($pos + $longitud > $len) {
            throw new \RuntimeException('DER truncado (contenido)');
        }

        return [
            'tag'          => $tag,
            'length'       => $longitud,
            'headerLength' => $pos - $offset,
            'content'      => substr($der, $pos, $longitud),
        ];
    }

    /**
     * Lee todas las TLV hermanas dentro de un bloque de contenido (ej. el
     * contenido de una SEQUENCE), en orden.
     *
     * @return list<array{tag:int, length:int, headerLength:int, content:string}>
     */
    private static function leerHijos(string $contenido): array
    {
        $hijos  = [];
        $offset = 0;
        $len    = strlen($contenido);
        while ($offset < $len) {
            $tlv      = self::leerTlv($contenido, $offset);
            $hijos[]  = $tlv;
            $offset  += $tlv['headerLength'] + $tlv['length'];
        }

        return $hijos;
    }

    /** Decodifica un OBJECT IDENTIFIER DER (bytes ya sin tag/longitud) a su forma "1.3.6...". */
    private static function decodificarOid(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len === 0) {
            return '';
        }

        $primerByte = ord($bytes[0]);
        $x          = intdiv($primerByte, 40);
        if ($x > 2) {
            $x = 2;
        }
        $arcos = [$x, $primerByte - 40 * $x];

        $valor = 0;
        for ($i = 1; $i < $len; $i++) {
            $b     = ord($bytes[$i]);
            $valor = ($valor << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                $arcos[] = $valor;
                $valor   = 0;
            }
        }

        return implode('.', $arcos);
    }
}
