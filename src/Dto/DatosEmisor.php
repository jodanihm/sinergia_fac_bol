<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Datos PUBLICOS del emisor necesarios para armar el encabezado de un DTE.
 *
 * No contiene material criptografico (eso vive en {@see Certificado}). Aqui
 * solo van datos que el SII espera ver en el XML del DTE: razon social, giro,
 * codigo de actividad economica (acteco), direccion de origen y datos de la
 * resolucion de electronica.
 *
 * Es responsabilidad del repositorio devolverlos por (rutEmisor, ambiente).
 */
final readonly class DatosEmisor
{
    public function __construct(
        public string $rutEmisor,
        public string $razonSocial,
        public string $giro,
        public int    $acteco,
        public string $dirOrigen,
        public string $cmnaOrigen,
        public string $resolucionFecha, // YYYY-MM-DD
        public int    $resolucionNumero = 0,
    ) {
        if (trim($this->rutEmisor) === '') {
            throw new DocumentoInvalidoException('DatosEmisor: rutEmisor no puede ser vacio');
        }
        if (trim($this->razonSocial) === '') {
            throw new DocumentoInvalidoException('DatosEmisor: razonSocial no puede ser vacio');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->resolucionFecha)) {
            throw new DocumentoInvalidoException(
                'DatosEmisor: resolucionFecha debe ser YYYY-MM-DD'
            );
        }
    }
}
