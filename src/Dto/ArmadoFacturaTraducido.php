<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Lo que el modelo entendio de un pedido de armar factura.
 *
 * ES EL HERMANO DE PreguntaTraducida, NO SU REEMPLAZO. Aquel traduce una pregunta
 * a las cinco perillas de una consulta; este traduce un pedido a un borrador. Se
 * mantienen separados porque el otro camino ya corre en produccion y su
 * interprete es lista cerrada: agregarle desenlaces lo haria explotar.
 *
 * =============================================================================
 * CUATRO DESENLACES, Y CADA UNO EXISTE POR UN CASO QUE PASA
 * =============================================================================
 *
 *   faltanDatos   Entendio el pedido pero le falta algo para armarlo: el RUT del
 *                 cliente, el precio, la forma de pago. Lleva LA PREGUNTA que hay
 *                 que hacerle al usuario, y el borrador PARCIAL de lo que ya se
 *                 sabe. Es el desenlace mas frecuente: armar una factura
 *                 conversando es, casi siempre, varias vueltas de esto.
 *
 *   borradorListo Tiene todo. Lleva el borrador completo -- cliente, documentos y
 *                 sus lineas -- para que el panel lo materialice. NO ESTA
 *                 VALIDADO: ver mas abajo.
 *
 *   cambioDeTema  Lo que escribio el usuario NO es continuacion de la
 *                 conversacion en curso. Es el caso de "¿cuanto facture en
 *                 julio?" en mitad de un armado. Existe para que la decision la
 *                 tome el modelo -- que ya esta leyendo la frase -- y no una
 *                 heuristica en PHP compitiendo con el.
 *
 *                 SOLO EXISTE SI HAY UNA CONVERSACION EN CURSO. En el primer
 *                 turno no hay nada que abandonar, asi que "esto no continua lo
 *                 que se venia armando" seria cierto siempre y el desenlace se
 *                 tragaria cualquier pedido. El traductor ni lo ofrece ni lo
 *                 acepta en ese caso -- ver DeepSeekTraductorArmadoFactura.
 *
 *   esConsulta    El mensaje SE ENTIENDE, pero no pide armar nada: es una
 *                 pregunta sobre datos que ya existen. Se coló por la heuristica
 *                 de ruteo, que mira si la frase menciona facturar. El panel lo
 *                 manda al camino de consultas dentro de la MISMA peticion.
 *
 *                 ES LA RED DE SEGURIDAD DE LA HEURISTICA, y existe porque esa
 *                 red se rompio sin que nadie lo notara. El diseño original
 *                 aceptaba que la heuristica se equivocara porque el error se
 *                 recuperaba solo via cambioDeTema. Al cerrar cambioDeTema para
 *                 el primer turno -- correcto, arreglaba otro defecto -- el
 *                 rescate desaparecio y un ruteo equivocado paso a ser terminal:
 *                 "puedes mostrarme los ultimos clientes facturados" recibia
 *                 "solo puedo ayudarte a preparar borradores de facturas".
 *
 *   noEntendida   No se entendio NINGUNA intencion: ni pedido ni pregunta. Lleva
 *                 motivo, que se le muestra al usuario tal cual.
 *
 * NO HAY UN DESENLACE "IMPOSIBLE". En el traductor de consultas existe porque hay
 * preguntas que los datos no pueden responder ("que producto vendi mas"). Aqui no
 * hay equivalente: si falta un dato, se pide; si el pedido no se entiende, es
 * noEntendida. Inventarlo dejaria un cajon donde el modelo tiraria lo que no
 * quiso resolver.
 *
 * =============================================================================
 * EL BORRADOR VIENE SIN VALIDAR, A PROPOSITO
 * =============================================================================
 *
 * Mismo criterio que PreguntaTraducida con las perillas: lo que el modelo dijo se
 * devuelve tal cual. Validar es del panel -- el RUT con Rut::valido(), el cliente
 * con validarCliente(), los largos contra las columnas --, y si esta capa
 * validara tambien habria dos validadores capaces de desincronizarse. Un cliente
 * alucinado tiene que dar el MISMO rechazo que un POST malformado.
 *
 * Y EL PANEL NO PUEDE CONFIAR EN NINGUNA CIFRA DE AQUI sin haberla pasado por su
 * propia validacion: esto es texto que escribio un modelo, no una entrada de
 * formulario.
 */
final class ArmadoFacturaTraducido
{
    public const FALTAN_DATOS   = 'faltan_datos';
    public const BORRADOR_LISTO = 'borrador_listo';
    public const CAMBIO_DE_TEMA = 'cambio_de_tema';
    public const ES_CONSULTA    = 'es_consulta';
    public const NO_ENTENDIDA   = 'no_entendida';

    /**
     * @param array<string,mixed> $borrador Lo que el modelo entendio, SIN VALIDAR.
     *        Completo en BORRADOR_LISTO, parcial en FALTAN_DATOS, vacio en los
     *        otros dos.
     * @param string|null $pregunta Lo que hay que preguntarle al usuario. Solo en
     *        FALTAN_DATOS.
     * @param string|null $motivo Explicacion para el usuario. Solo en
     *        NO_ENTENDIDA.
     */
    private function __construct(
        public readonly string $desenlace,
        public readonly array $borrador,
        public readonly ?string $pregunta,
        public readonly ?string $motivo,
    ) {
    }

    /** @param array<string,mixed> $borradorParcial */
    public static function faltanDatos(string $pregunta, array $borradorParcial = []): self
    {
        return new self(self::FALTAN_DATOS, $borradorParcial, $pregunta, null);
    }

    /** @param array<string,mixed> $borrador */
    public static function borradorListo(array $borrador): self
    {
        return new self(self::BORRADOR_LISTO, $borrador, null, null);
    }

    /**
     * El mensaje no continua la conversacion en curso.
     *
     * NO LLEVA EL TEXTO A REINTERPRETAR: el panel ya lo tiene -- es lo que acaba
     * de recibir por POST -- y hacerlo viajar de vuelta daria dos copias de la
     * misma frase, con la del modelo posiblemente reescrita.
     */
    public static function cambioDeTema(): self
    {
        return new self(self::CAMBIO_DE_TEMA, [], null, null);
    }

    /**
     * No pide armar nada: es una pregunta. Al camino de consultas.
     *
     * NO LLEVA TEXTO, igual que cambioDeTema y por el mismo motivo: el panel ya
     * tiene la frase -- es la que acaba de recibir -- y hacerla viajar de vuelta
     * daria dos copias de lo mismo, con la del modelo posiblemente reescrita.
     */
    public static function esConsulta(): self
    {
        return new self(self::ES_CONSULTA, [], null, null);
    }

    public static function noEntendida(string $motivo): self
    {
        return new self(self::NO_ENTENDIDA, [], null, $motivo);
    }

    /**
     * ¿Este turno lo tiene que atender el camino de consultas?
     *
     * DOS DESENLACES DISTINTOS CON LA MISMA CONSECUENCIA, y siguen separados a
     * proposito: cambioDeTema solo existe con un borrador en curso -- y esa
     * restriccion es la que arreglo el defecto del 12-08 --, mientras que
     * esConsulta vale siempre. Fundirlos en uno obligaria a levantar esa guarda y
     * reabriria aquel bug.
     */
    public function vaAConsultas(): bool
    {
        return $this->desenlace === self::CAMBIO_DE_TEMA || $this->desenlace === self::ES_CONSULTA;
    }

    /** ¿Hay algo que materializar? */
    public function hayQueArmar(): bool
    {
        return $this->desenlace === self::BORRADOR_LISTO;
    }

    /** ¿La conversacion sigue abierta esperando al usuario? */
    public function sigueAbierta(): bool
    {
        return $this->desenlace === self::FALTAN_DATOS;
    }
}
