<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Lo que hay que saber para pedirle a una pasarela que cobre UN documento.
 *
 * Es independiente de la pasarela a proposito: aqui no hay ni un nombre de campo
 * de Flow. Traducir esto al vocabulario de cada proveedor es trabajo de su
 * implementacion, que es justamente lo que permite que entre una segunda sin
 * tocar a quien construye esta solicitud.
 */
final readonly class SolicitudPago
{
    /**
     * @param string $referencia   NUESTRA clave de la orden, determinista y unica.
     *                             Es la que hace idempotente el reintento (regla 1
     *                             de PasarelaPagoInterface), asi que NO puede ser
     *                             aleatoria ni depender de la hora.
     * @param int    $monto        pesos enteros, sin decimales
     * @param string $asunto       lo que el pagador va a leer al pagar
     * @param string $emailPagador a quien le llega el comprobante de la pasarela
     */
    public function __construct(
        public string $referencia,
        public int $monto,
        public string $asunto,
        public string $emailPagador,
        public string $urlConfirmacion,
        public ?string $urlRetorno = null,
    ) {
        if (trim($this->referencia) === '') {
            throw new DocumentoInvalidoException('SolicitudPago: referencia no puede ser vacia');
        }
        if ($this->monto <= 0) {
            throw new DocumentoInvalidoException('SolicitudPago: monto debe ser mayor que cero');
        }
        if (trim($this->asunto) === '') {
            throw new DocumentoInvalidoException('SolicitudPago: asunto no puede ser vacio');
        }
        // urlConfirmacion NO es opcional, y no es un capricho nuestro: Flow la
        // exige y la usa para confirmar el pago. Una orden creada con una URL
        // que no responde puede quedarse sin confirmar, o sea cobrada y sin que
        // nos enteremos. Que sea obligatoria en el DTO lo hace imposible de
        // olvidar en cualquier implementacion futura.
        if (trim($this->urlConfirmacion) === '') {
            throw new DocumentoInvalidoException('SolicitudPago: urlConfirmacion no puede ser vacia');
        }
    }
}
