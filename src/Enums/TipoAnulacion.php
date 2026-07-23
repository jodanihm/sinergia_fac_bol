<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

/**
 * Tipo de anulacion/correccion via Nota de Credito.
 *
 * Los valores corresponden EXACTAMENTE al campo CodRef del SII en el bloque
 * Referencia del DTE: el codigo de motivo de la referencia.
 *
 *   1 = Anula Documento de Referencia  (anulacion total)
 *   2 = Corrige Texto                  (no afecta montos)
 *   3 = Corrige Monto                  (anula y reemplaza, ej rebaja)
 */
enum TipoAnulacion: int
{
    case AnulaTotal = 1;
    case CorrigeTexto = 2;
    case CorrigeMonto = 3;
}
