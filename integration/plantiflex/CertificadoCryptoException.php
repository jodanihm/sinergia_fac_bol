<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Fallo en cifrado/descifrado del certificado: llave maestra invalida, blob
 * alterado, o validacion GCM rota.
 *
 * El mensaje JAMAS debe contener ni la llave maestra ni el dato en claro.
 */
class CertificadoCryptoException extends RuntimeException
{
}
