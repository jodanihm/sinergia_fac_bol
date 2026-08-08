<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Representa el DTE original que se quiere anular o corregir.
 *
 * Diseñado para que el caller NOS pase los datos (receptor, detalles, montos,
 * fecha) en vez de que esta capa tenga que consultarlos al proveedor. Esto
 * desacopla la anulacion de endpoints de consulta que pueden no estar
 * confirmados.
 *
 * Los detalles y los totales son OBLIGATORIOS porque una NC valida ante el SII
 * tiene que replicar montos del documento original (no se pueden inventar ni
 * dejar en cero).
 *
 *
 * montoTotal ADMITE CERO, Y ESO CAMBIO
 * -----------------------------------------------------------------------------
 * Antes exigia > 0. La regla parecia razonable y era falsa: una NOTA DE
 * CORRECCION DE TEXTO lleva MntTotal 0 POR DISEÑO -- solo corrige datos, no
 * mueve plata --, y este mismo repo la emite asi (SiiDirectoFacturador::anular()
 * con TipoAnulacion::CorrigeTexto usa totales: ['MntTotal' => 0]). Medido en
 * produccion: cinco documentos con total cero, tipo 56 folios 12 y 13 y tipo 61
 * folios 37, 38 y 39. Con la guarda vieja, ninguno de esos se podia anular ni
 * referenciar.
 *
 * POR QUE ESTABA, Y POR QUE YA NO HACE FALTA. Servia de CANARIO: una
 * reconstruccion rota devolvia todos los campos en cero, y el total en cero era
 * lo que lo delataba. Ese sintoma existia porque reconstruirOriginal() leia con
 * item(0) sobre el sobre entero y podia no encontrar nada. Hoy pasa por
 * Sii\DocumentoDelSobre::ubicar(), que LANZA ante XML vacio, ilegible o
 * documento ausente: la reconstruccion rota ya no puede llegar hasta aqui en
 * silencio.
 *
 * LO QUE ubicar() NO CUBRE -- un documento presente pero sin <Totales> -- se
 * comprueba donde SI se puede distinguir "el elemento no vino" de "vale cero":
 * en reconstruirOriginal(), sobre el texto crudo. Un DTO no puede diferenciar
 * esas dos cosas porque las dos le llegan como int 0.
 *
 * El negativo sigue prohibido: eso no es un documento, es un error de signo.
 */
final readonly class DocumentoOriginal
{
    /**
     * @param list<Detalle> $detalles
     */
    public function __construct(
        public TipoDte           $tipoDte,
        public int               $folio,
        public DateTimeImmutable $fechaEmision,
        public Receptor          $receptor,
        public array             $detalles,
        public int               $montoNeto,
        public int               $iva,
        public int               $montoTotal,
        public bool              $montosSonBrutos = false,
    ) {
        if ($this->folio <= 0) {
            throw new DocumentoInvalidoException('DocumentoOriginal: folio debe ser > 0');
        }
        if ($this->detalles === []) {
            throw new DocumentoInvalidoException('DocumentoOriginal: detalles no puede ser vacio');
        }
        foreach ($this->detalles as $i => $d) {
            if (! $d instanceof Detalle) {
                throw new DocumentoInvalidoException("DocumentoOriginal: detalles[$i] no es Detalle");
            }
        }
        // Cero SI: nota de correccion de texto. Negativo no. Ver el docblock.
        if ($this->montoTotal < 0) {
            throw new DocumentoInvalidoException('DocumentoOriginal: montoTotal no puede ser negativo');
        }
    }
}
