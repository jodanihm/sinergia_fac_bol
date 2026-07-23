<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\EstadoDte;
use Plantiflex\FacturacionCl\Dto\ResultadoEmision;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Exceptions\FacturacionException;

/**
 * Contrato agnostico al proveedor para facturacion electronica chilena.
 *
 * Las credenciales viajan en cada metodo (no se guardan en el estado de la
 * instancia) para permitir multi-tenant: una sola instancia puede atender
 * distintos RUT emisores segun quien llame.
 */
interface FacturadorInterface
{
    /**
     * Emite un documento tributario electronico.
     *
     * @throws FacturacionException
     */
    public function emitir(DocumentoTributario $doc, Credenciales $cred): ResultadoEmision;

    /**
     * Consulta el estado de un DTE ya emitido.
     *
     * @param int $folio    Folio del documento.
     * @param int $tipoDte  Valor entero del tipo de DTE (ver Enums\TipoDte).
     *
     * @throws FacturacionException
     */
    public function consultarEstado(int $folio, int $tipoDte, Credenciales $cred): EstadoDte;

    /**
     * Anula o corrige un DTE emitiendo una Nota de Credito (tipo 61).
     *
     * El caller PASA los datos del documento original (receptor, montos,
     * detalles, fecha) en {@see DocumentoOriginal}. La implementacion concreta
     * arma la NC tributariamente valida: replica el receptor y montos del
     * original, construye la Referencia con TpoDocRef/FolioRef/CodRef/RazonRef
     * /FchRef, y emite la NC por el flujo normal (asigna su propio folio de la
     * serie 61).
     *
     * El parametro $tipo distingue intencion de negocio:
     *   - AnulaTotal   (CodRef=1): anula el documento completo.
     *   - CorrigeMonto (CodRef=3): corrige montos (no implementado todavia).
     *   - CorrigeTexto (CodRef=2): corrige texto/datos (no implementado todavia).
     *
     * El codigo consumidor no necesita saber que se emite una NC.
     *
     * @throws FacturacionException
     */
    public function anular(
        DocumentoOriginal $original,
        string $motivo,
        TipoAnulacion $tipo,
        Credenciales $cred,
    ): ResultadoEmision;

    /**
     * Identificador legible del proveedor (ej: "libredte", "openfactura").
     */
    public function nombreProveedor(): string;
}
