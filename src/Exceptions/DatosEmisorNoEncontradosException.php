<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * No existen datos publicos del emisor (razon social, giro, resolucion, etc.)
 * registrados para el (rutEmisor, ambiente) solicitado.
 *
 * Distinto de CertificadoNoEncontradoException: aquel apunta a material
 * criptografico faltante; este apunta a datos administrativos faltantes. Es
 * util distinguirlos porque la solucion operativa es distinta (subir CAF/cert
 * vs. cargar datos del emisor en un formulario).
 */
class DatosEmisorNoEncontradosException extends FacturacionException
{
}
