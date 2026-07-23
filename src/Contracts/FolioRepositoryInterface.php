<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;

/**
 * Contrato para gestionar folios y CAF de DTE chilenos.
 *
 * Existe para proveedores que NO administran el CAF en su backend (ej:
 * API Gateway / apigateway.cl). En esos casos somos nosotros quienes:
 *   - cargamos el XML del CAF emitido por el SII,
 *   - llevamos el contador de folios de forma atomica,
 *   - garantizamos que cada folio se entregue UNA sola vez.
 *
 * Reglas tributarias que toda implementacion DEBE respetar:
 *
 * 1. Numeracion correlativa sin saltos ni repeticiones. Si dos llamadas
 *    concurrentes piden el siguiente folio, JAMAS deben recibir el mismo
 *    numero. Esto exige atomicidad real a nivel de almacenamiento.
 *
 * 2. Un folio se "quema" al asignarse, no al emitirse con exito. Si el SII
 *    rechaza un DTE, el folio NO se reutiliza libremente: queda registrado
 *    con emision_exitosa=false. Reutilizar folios rechazados es decision
 *    del area tributaria, no de esta capa.
 *
 * 3. Multi-tenant: todas las consultas se filtran por rutEmisor. La
 *    implementacion no debe asumir un emisor "default".
 *
 * 4. Multi-ambiente: certificacion y produccion usan CAFs distintos. Nunca
 *    mezclarlos.
 *
 * Este contrato NO toca certificados ni llaves privadas: solo folios y CAF.
 */
interface FolioRepositoryInterface
{
    /**
     * Asigna y devuelve el siguiente folio disponible de forma ATOMICA.
     *
     * Recorre los CAF activos del emisor/tipo/ambiente en orden ascendente
     * de rango y entrega el primer folio libre. Si un CAF queda agotado en
     * esta llamada, debe marcarlo como tal. Si NINGUN CAF tiene folios
     * disponibles, lanza FoliosAgotadosException.
     *
     * Tambien debe registrar el folio asignado en la bitacora con resultado
     * pendiente (sera completado luego por marcarFolioComoUsado).
     *
     * @throws FoliosAgotadosException
     */
    public function asignarSiguienteFolio(string $rutEmisor, TipoDte $tipo, Ambiente $ambiente): int;

    /**
     * Devuelve el XML del CAF (ya descifrado / decodificado, listo para usar
     * por el firmador) que cubre el folio actualmente vigente del
     * emisor/tipo/ambiente. Si no hay CAF activo, lanza FoliosAgotadosException.
     *
     * @throws FoliosAgotadosException
     */
    public function obtenerCafActivo(string $rutEmisor, TipoDte $tipo, Ambiente $ambiente): string;

    /**
     * Registra el resultado real de la emision del folio.
     *
     * Esto NO libera el folio para reutilizarlo: solo deja constancia en la
     * bitacora. Es responsabilidad del flujo de emision llamar a este metodo
     * tanto para exito como para rechazo.
     *
     * El parametro $ambiente es obligatorio porque el mismo folio puede existir
     * legitimamente en certificacion y produccion para un mismo emisor/tipo;
     * filtrar solo por (rut, tipo, folio) actualizaria el registro equivocado.
     */
    public function marcarFolioComoUsado(
        string $rutEmisor,
        TipoDte $tipo,
        Ambiente $ambiente,
        int $folio,
        bool $emisionExitosa,
    ): void;
}
