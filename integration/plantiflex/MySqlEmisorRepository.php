<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\DatosEmisorNoEncontradosException;

/**
 * Implementacion del EmisorRepositoryInterface contra MySQL / PDO.
 *
 * DOS TABLAS A PROPOSITO:
 *   - dte_certificado: material sensible (cert + pkey) CIFRADO AES-256-GCM.
 *   - dte_emisor:      datos publicos (razon social, giro, acteco,
 *                      resolucion) en CLARO.
 *
 * La separacion permite tratar cada tabla con politicas distintas de respaldo
 * y acceso: la tabla cifrada puede salir en respaldos generales sin riesgo,
 * mientras que la llave maestra del cifrado se administra fuera de la BD.
 */
final class MySqlEmisorRepository implements EmisorRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CertificadoCrypto $crypto,
    ) {
    }

    public function obtenerCertificado(string $rutEmisor, Ambiente $ambiente): Certificado
    {
        $stmt = $this->pdo->prepare(
            'SELECT cert_data_cifrado, pkey_data_cifrado, dek_envuelta '
            . 'FROM dte_certificado '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb '
            . 'LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new CertificadoNoEncontradoException(sprintf(
                'No hay certificado registrado para %s en ambiente %s',
                $rutEmisor,
                $ambiente->value,
            ));
        }

        // Envelope encryption (DEK aleatoria por certificado, envuelta con la
        // KEK) con fallback LEGACY: si dek_envuelta viene NULL/vacia, el dato
        // se cifro directo con la KEK (esquema anterior) y se descifra igual
        // que siempre. La MISMA dek_envuelta cifro cert y pkey, asi que se
        // resuelve una sola vez y se usa para ambos.
        $dekEnvuelta = (string) ($row['dek_envuelta'] ?? '');
        $crypto      = trim($dekEnvuelta) === ''
            ? $this->crypto
            : new CertificadoCrypto($this->crypto->descifrar($dekEnvuelta));

        return new Certificado(
            certData: $crypto->descifrar((string) $row['cert_data_cifrado']),
            pkeyData: $crypto->descifrar((string) $row['pkey_data_cifrado']),
        );
    }

    public function obtenerDatosEmisor(string $rutEmisor, Ambiente $ambiente): DatosEmisor
    {
        $stmt = $this->pdo->prepare(
            'SELECT razon_social, giro, acteco, dir_origen, cmna_origen, '
            . '       resolucion_fecha, resolucion_numero '
            . 'FROM dte_emisor '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb '
            . 'LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new DatosEmisorNoEncontradosException(sprintf(
                'No hay datos de emisor registrados para %s en ambiente %s',
                $rutEmisor,
                $ambiente->value,
            ));
        }

        return new DatosEmisor(
            rutEmisor:        $rutEmisor,
            razonSocial:      (string) $row['razon_social'],
            giro:             (string) $row['giro'],
            acteco:           (int)    $row['acteco'],
            dirOrigen:        (string) $row['dir_origen'],
            cmnaOrigen:       (string) $row['cmna_origen'],
            resolucionFecha:  (string) $row['resolucion_fecha'],
            resolucionNumero: (int)    $row['resolucion_numero'],
        );
    }
}
