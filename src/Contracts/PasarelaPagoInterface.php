<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Dto\OrdenPagoCreada;
use Plantiflex\FacturacionCl\Dto\SolicitudPago;
use Plantiflex\FacturacionCl\Exceptions\PasarelaPermanenteException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;

/**
 * Crea una orden de cobro en la pasarela de pagos de una empresa y devuelve el
 * link unico con el que su cliente puede pagar ESE documento.
 *
 * POR QUE HAY UNA INTERFAZ PARA UNA SOLA IMPLEMENTACION. Mismo criterio que
 * ConsultaContribuyenteInterface: la pasarela es una decision comercial de cada
 * empresa, no una decision tecnica nuestra. Hoy la unica implementada es Flow;
 * una empresa que cobre por otra no deberia obligarnos a tocar el correo, el
 * runner ni la base. Lo que cambia es una clase y una cadena en
 * pago_pasarela_cuenta.proveedor.
 *
 *
 * LAS TRES REGLAS QUE TODA IMPLEMENTACION TIENE QUE CUMPLIR
 * -----------------------------------------------------------------------------
 *
 * REGLA 1 -- IDEMPOTENCIA POR referencia. Dos llamadas con la misma
 *   $solicitud->referencia NO pueden producir dos cobros. La referencia se manda
 *   como clave de orden del comercio, y si la pasarela responde "esa orden ya
 *   existe" hay que devolver la existente, no lanzar. Esto no es un lujo: el
 *   caso que cubre es que la orden se cree alla y la respuesta se pierda en el
 *   camino, y entonces reintentamos. Sin esta regla, ahi nace un doble cobro.
 *
 * REGLA 2 -- CLASIFICAR EL FALLO EN DOS, Y SOLO DOS. Todo error sale como
 *   PasarelaTransitoriaException (tiene sentido reintentar: timeout, 5xx, 429)
 *   o PasarelaPermanenteException (no lo tiene: credenciales rechazadas, monto
 *   invalido, respuesta que no se entiende). Dejar escapar cualquier otra
 *   excepcion es un defecto de la implementacion.
 *
 *   Esa division no es cosmetica: es EL DATO con el que el resolutor decide si
 *   una factura espera cinco minutos o si se aparca un dia y alguien la mira. Un
 *   fallo permanente reintentado cada 5 minutos es ruido eterno; uno transitorio
 *   dado por permanente retiene una factura sin motivo.
 *
 * REGLA 3 -- EL MONTO VA EN PESOS ENTEROS. Sin decimales y sin separadores. Es
 *   lo que guarda dte_emitido.total y lo que entiende el SII.
 */
interface PasarelaPagoInterface
{
    /**
     * Clave estable de la implementacion ('flow'). Se persiste en
     * dte_pago_link.proveedor, asi que cambiarla rompe las filas ya escritas.
     */
    public function nombre(): string;

    /**
     * @throws PasarelaTransitoriaException reintentar mas tarde tiene sentido
     * @throws PasarelaPermanenteException  reintentar no va a arreglar nada
     */
    public function crearOrden(SolicitudPago $solicitud, CredencialesPasarela $cred): OrdenPagoCreada;

    /**
     * Que paso con una orden. SOLO LECTURA: no cobra, no anula, no reembolsa.
     *
     * HACE FALTA Y NO ES UN EXTRA. La pasarela avisa de una operacion TANTO SI SE
     * PAGO COMO SI SE RECHAZO, y su aviso no siempre dice cual de las dos.
     * Creerse que todo aviso es un pago marcaria como pagadas facturas que
     * nadie pago -- un error que no se ve hasta que alguien reclama.
     *
     * @param string $referenciaExterna lo que identifica la operacion en la
     *                                  pasarela (para Flow, el token)
     *
     * @return array{pagada:bool, referencia:string, monto:?int}
     *         referencia es NUESTRA clave (commerceOrder), que es por donde se
     *         encuentra la fila sin depender de como la llame cada proveedor.
     *
     * @throws PasarelaTransitoriaException
     * @throws PasarelaPermanenteException
     */
    public function consultarEstado(string $referenciaExterna, CredencialesPasarela $cred): array;
}
