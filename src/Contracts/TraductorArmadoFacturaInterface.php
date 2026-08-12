<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\ArmadoFacturaTraducido;
use Plantiflex\FacturacionCl\Dto\VocabularioArmadoFactura;
use Plantiflex\FacturacionCl\Exceptions\TraduccionArmadoException;

/**
 * Traduce un pedido en lenguaje natural a un borrador de factura.
 *
 * INTERFAZ PROPIA Y NO EL SDK DEL PROVEEDOR, mismo criterio que
 * TraductorPreguntaInterface, ConsultaContribuyenteInterface y BrevoMailer.
 *
 * =============================================================================
 * POR QUE ES UNA INTERFAZ APARTE Y NO UN METODO MAS EN TraductorPreguntaInterface
 * =============================================================================
 *
 * Tres motivos medidos, no de gusto:
 *
 *   1. La firma no encaja. traducir() recibe una frase suelta; esto necesita el
 *      hilo de la conversacion y devuelve otra cosa.
 *   2. El interprete del otro es lista cerrada: ante un desenlace que no conoce
 *      lanza respuestaIlegible(). Agregarle desenlaces romperia el camino que hoy
 *      corre en produccion.
 *   3. El prompt del otro empieza diciendo "NO respondes la pregunta, NO calculas
 *      nada". Un prompt que ademas arme facturas se contradice en su primera
 *      linea.
 *
 * =============================================================================
 * QUE VIAJA AL MODELO, Y QUE NO -- ESTO ES LA REGLA DE PRIVACIDAD
 * =============================================================================
 *
 * ENTRAN EXACTAMENTE DOS COSAS, Y LAS DOS SALIERON YA DE ESTA CONVERSACION:
 *
 *   $turnosUsuario   Las frases que escribio la persona, en orden. Nada mas.
 *   $borradorPrevio  El borrador que ESTE MISMO TRADUCTOR devolvio en el turno
 *                    anterior, tal como lo devolvio. Sirve para que el modelo no
 *                    tenga que reconstruir en cada vuelta lo que ya habia
 *                    entendido.
 *
 * NI UNA DE LAS DOS PUEDE CONTENER DATOS DEL TENANT, y no por disciplina sino por
 * de donde vienen: una es tecleada por el usuario y la otra la escribio el modelo.
 * Lo que el panel resuelve del maestro -- la razon social, el giro y la direccion
 * que devuelve un RUT, el cliente que existia, los montos ya guardados -- se
 * guarda en la sesion en OTRA clave y NO se pasa por aqui nunca.
 *
 * ESO NO ES UNA RECOMENDACION: ES LA FIRMA. Este metodo no admite cuenta_id, ni
 * una fila del maestro, ni un total. No se puede filtrar lo que no se recibe, que
 * es la misma garantia estructural que ya da traducir() en el otro traductor.
 *
 * TAMPOCO ENTRAN LAS PREGUNTAS QUE EL PROPIO MODELO HIZO. Podrian entrar sin
 * romper nada -- son suyas --, pero $borradorPrevio ya lleva el estado que esas
 * preguntas perseguian, y mandar las dos cosas seria pagar dos veces por el mismo
 * contexto.
 *
 * LIMITE CONOCIDO, HEREDADO Y ACEPTADO: si el usuario escribe el RUT o el nombre
 * de su cliente, eso viaja dentro de su propia frase. Es su pedido, no un dato que
 * el sistema haya ido a buscar. La pantalla del chat ya lo dice con esas palabras.
 *
 * =============================================================================
 * LO QUE ESTA INTERFAZ NO HACE
 * =============================================================================
 *
 *   NO VALIDA. El borrador que devuelve es lo que el modelo dijo. El RUT puede ser
 *   invalido, el precio puede ser absurdo y el giro puede pasarse de largo. Validar
 *   es del panel.
 *
 *   NO CONSULTA LA BASE. Ni la toca. No sabe si el cliente existe: eso lo resuelve
 *   el panel DESPUES, con el RUT que el usuario escribio.
 *
 *   NO DECIDE SI HAY CUPO NI CUANTO CUESTA. Eso es del llamador, y tiene que
 *   mirarlo ANTES de llamar.
 */
interface TraductorArmadoFacturaInterface
{
    /**
     * @param list<string> $turnosUsuario Lo que el usuario escribio, en orden,
     *        incluido el mensaje de ahora como ultimo elemento. SOLO sus frases.
     * @param array<string,mixed> $borradorPrevio Lo que este traductor devolvio la
     *        vuelta anterior, tal cual. Vacio en el primer turno.
     * @param VocabularioArmadoFactura $vocabulario Opciones validas, para el prompt.
     * @param string $hoy Fecha de referencia AAAA-MM-DD. Se pasa y no se toma del
     *        reloj del servidor para que el resultado sea reproducible en un test.
     *
     * @throws TraduccionArmadoException si no hay credencial, si el proveedor no
     *         respondio, o si respondio algo que no se pudo interpretar.
     */
    public function traducir(
        array $turnosUsuario,
        array $borradorPrevio,
        VocabularioArmadoFactura $vocabulario,
        string $hoy,
    ): ArmadoFacturaTraducido;
}
