<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

/**
 * Cifrado autenticado AES-256-GCM para certificados y llaves privadas.
 *
 * Formato del blob retornado por cifrar():
 *   base64( IV (12B) || TAG (16B) || CIPHERTEXT )
 *
 * GCM da confidencialidad + autenticidad: descifrar() falla si el blob fue
 * alterado en cualquier byte (tag no valida).
 *
 * SEGURIDAD:
 *   - La llave maestra (32 bytes = 256 bits) se inyecta como string en el
 *     constructor. Esta clase NO la lee de getenv/env/$_SERVER: la responsabilidad
 *     de obtenerla del entorno seguro es del sistema anfitrion (eso permite
 *     testear sin tocar variables globales).
 *   - Esta clase NUNCA debe loguear la llave maestra ni los datos en claro,
 *     ni siquiera en mensajes de excepcion.
 */
final class CertificadoCrypto
{
    public const CIPHER = 'aes-256-gcm';
    public const KEY_LENGTH = 32; // 256 bits
    public const IV_LENGTH = 12;  // recomendado por NIST para GCM
    public const TAG_LENGTH = 16; // 128 bits

    /**
     * @param string $llaveMaestra Bytes crudos de 32 (NO base64, NO hex). El anfitrion
     *                             decide como obtenerla (env var, KMS, etc.).
     */
    public function __construct(
        private readonly string $llaveMaestra,
    ) {
        if (strlen($this->llaveMaestra) !== self::KEY_LENGTH) {
            throw new CertificadoCryptoException(sprintf(
                'Llave maestra invalida: se esperaban %d bytes crudos, recibidos %d',
                self::KEY_LENGTH,
                strlen($this->llaveMaestra),
            ));
        }
    }

    public function cifrar(string $plano): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipher = openssl_encrypt(
            $plano,
            self::CIPHER,
            $this->llaveMaestra,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($cipher === false) {
            throw new CertificadoCryptoException('Fallo cifrado AES-256-GCM');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function descifrar(string $cifrado): string
    {
        $blob = base64_decode($cifrado, true);
        if ($blob === false || strlen($blob) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new CertificadoCryptoException('Blob cifrado invalido o truncado');
        }

        $iv     = substr($blob, 0, self::IV_LENGTH);
        $tag    = substr($blob, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($blob, self::IV_LENGTH + self::TAG_LENGTH);

        $plano = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $this->llaveMaestra,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plano === false) {
            // No incluir detalles: el caller decide si distinguir "blob roto"
            // de "llave equivocada". GCM falla igual en ambos casos.
            throw new CertificadoCryptoException(
                'Fallo descifrado AES-256-GCM (tag no valido o dato alterado)'
            );
        }

        return $plano;
    }
}
