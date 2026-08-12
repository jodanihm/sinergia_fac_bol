<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Lo que el modelo puede pedir al armar una factura, para armar el prompt.
 *
 * MISMO PAPEL Y MISMO MOTIVO QUE VocabularioConsulta: las opciones no se escriben
 * en el prompt sino que se le pasan desde donde de verdad viven. Si el prompt
 * enumerara las formas de pago por su cuenta, el dia que la carga masiva acepte
 * una mas el modelo seguiria ofreciendo la lista vieja -- proponiendo algo que el
 * validador despues rechaza, que es la peor de las dos formas de desincronizarse.
 *
 * ES UNA CONVENCION DE CABLEADO, no una garantia del compilador: este objeto vive
 * en src/ (paquete agnostico) y las listas viven en el panel (sistema anfitrion).
 * Hacer que src/ dependa del panel seria invertir la dependencia. La garantia la
 * da quien lo construya en el panel, en un solo sitio.
 *
 * NO VALIDA NADA. Existe para CONTARLE al modelo que puede pedir, no para decidir
 * si lo que pidio sirve. Eso es del panel.
 */
final class VocabularioArmadoFactura
{
    /**
     * @param list<string> $formasPago Las palabras que acepta la carga masiva
     *        (CONTADO, CREDITO, SIN COSTO). Se le dan al modelo EN PALABRAS y no
     *        como los codigos 1/2/3 del SII: la traduccion a numero ocurre en el
     *        panel, igual que ocurre para quien llena el Excel a mano.
     * @param string $formaPagoPorDefecto La que se asume si el usuario no
     *        contesta. Va aqui y no escrita en el prompt porque es una decision
     *        de negocio que puede cambiar sin que cambie el traductor.
     * @param int $maxDocumentos Tope de documentos que puede proponer de una vez.
     *        Sin el, "facturale a todos mis clientes" produce un borrador de
     *        cientos de folios.
     * @param array<string,int> $maxLargos Largo maximo por campo del cliente
     *        (razonSocial, giro, direccion, comuna), tomado de las columnas
     *        reales. SE LE DICE AL MODELO en vez de recortar despues: un giro
     *        cortado a la mitad es peor que uno que el modelo escribio corto.
     */
    public function __construct(
        public readonly array $formasPago,
        public readonly string $formaPagoPorDefecto,
        public readonly int $maxDocumentos,
        public readonly array $maxLargos,
    ) {
    }

    /**
     * El bloque de texto que se le da al modelo. Se genera desde las listas, no se
     * escribe: es el punto entero de esta clase.
     */
    public function comoTexto(): string
    {
        $lineas = [
            'formaPago: ' . implode(' | ', $this->formasPago),
            'si el usuario no dice la forma de pago, se asume: ' . $this->formaPagoPorDefecto,
            'maximo de documentos en un mismo pedido: ' . $this->maxDocumentos,
        ];

        foreach ($this->maxLargos as $campo => $largo) {
            $lineas[] = "largo maximo de {$campo}: {$largo} caracteres";
        }

        return implode("\n", $lineas);
    }
}
