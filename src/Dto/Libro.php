<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Libro Electronico de Compra o Venta (IECV) de un periodo tributario.
 *
 * Reporta un resumen de documentos de un periodo (AAAA-MM). No usa CAF ni
 * consume folios; se firma con XML-DSig y se envia al SII.
 */
final readonly class Libro
{
    /**
     * @param list<LineaLibro> $lineas
     * @param float|null       $factorProporcionalidad Factor de proporcionalidad del IVA
     *        de uso comun (solo COMPRA). UNO para todo el libro; va en el ResumenPeriodo.
     * @param string|null      $codigoAutorizacionReemplazo Codigo de Autorizacion de
     *        Rectificacion (CodAutRec). Obligatorio cuando tipoLibro=RECTIFICA; el
     *        builder del XML (LibroXmlBuilder) es quien exige esta regla.
     */
    public function __construct(
        public TipoOperacionLibro $tipoOperacion,
        public string             $periodoTributario, // AAAA-MM
        public TipoLibro          $tipoLibro,
        public TipoEnvioLibro     $tipoEnvio,
        public int                $folioNotificacion,
        public array              $lineas,
        public ?float             $factorProporcionalidad = null,
        public ?string            $codigoAutorizacionReemplazo = null,
    ) {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->periodoTributario)) {
            throw new DocumentoInvalidoException('Libro: periodoTributario debe ser AAAA-MM');
        }
        foreach ($this->lineas as $i => $l) {
            if (! $l instanceof LineaLibro) {
                throw new DocumentoInvalidoException("Libro: lineas[$i] no es LineaLibro");
            }
        }
    }
}
