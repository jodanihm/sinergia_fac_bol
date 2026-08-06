<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Lo que sabemos de un contribuyente como EMISOR de documentos electronicos.
 *
 * OBJETO NUESTRO, NO DEL PROVEEDOR, Y ESO ES EL PUNTO. La consulta la resuelve
 * hoy API Gateway, pero ni este DTO ni quien lo consume conocen ese nombre: los
 * campos se llaman como los llama este proyecto (razonSocial, no razon_social) y
 * el dia que haya que cambiar de proveedor se toca la implementacion del
 * contrato y nada mas. Por lo mismo NO se usa el paquete de Composer del
 * proveedor: seria acoplar el arbol de dependencias a una decision comercial.
 *
 * QUE NO TRAE, Y HAY QUE DECIRLO PORQUE ES LA MITAD DEL FORMULARIO: la consulta
 * NO devuelve direccion ni comuna del contribuyente. Esos dos campos se siguen
 * tecleando a mano en /empresa y este DTO no los inventa ni los deja en blanco
 * simulando que se resolvieron.
 *
 * PARA QUE SIRVE DE VERDAD: resolucionNumero y resolucionFecha son los dos datos
 * que van a la Caratula de cada envio al SII (NroResol y FchResol). Un digito
 * mal tecleado ahi produce "RCT - Rechazado por Error en Caratula" y el envio
 * completo se cae; medido en produccion, 68 documentos rechazados de una vez y
 * nadie se entero hasta el dia siguiente. Traerlos de la fuente en vez de
 * tipearlos es el motivo por el que esta consulta existe.
 */
final readonly class ContribuyenteAutorizado
{
    /** @param list<DocumentoAutorizadoSii> $documentos */
    public function __construct(
        public string $rut,
        /**
         * false = el SII NO lo tiene habilitado como emisor electronico. NO es
         * un error de la consulta: es una respuesta valida y hay que mostrarla
         * como tal, porque le dice al usuario algo que necesita saber antes de
         * intentar emitir.
         */
        public bool $autorizado,
        public string $razonSocial,
        public ?int $resolucionNumero,
        /** YYYY-MM-DD, tal como lo entrega la fuente. */
        public ?string $resolucionFecha,
        public ?string $direccionRegional,
        public ?string $software,
        public array $documentos,
    ) {
    }

    /**
     * Los codigos de tipo de documento VIGENTES, ordenados.
     *
     * Excluye los desautorizados: aparecen en el arreglo igual, con su fecha de
     * desautorizacion, y contarlos como habilitados seria justo el error que
     * este dato viene a evitar.
     *
     * @return list<int>
     */
    public function codigosVigentes(): array
    {
        $codigos = [];
        foreach ($this->documentos as $doc) {
            if ($doc->vigente()) {
                $codigos[] = $doc->codigo;
            }
        }
        sort($codigos);

        return $codigos;
    }
}
