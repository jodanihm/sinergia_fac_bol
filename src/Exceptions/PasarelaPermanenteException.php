<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * Reintentar esto no va a arreglar nada: credenciales rechazadas, monto que la
 * pasarela no acepta, respuesta que no se entiende, proveedor desconocido.
 *
 * POR QUE IMPORTA DISTINGUIRLA de la transitoria: una clave mal pegada
 * reintentada cada 5 minutos es ruido eterno en el log y llamadas inutiles a un
 * tercero, y el correo igual no sale. Marcada como permanente, la orden se
 * aparca un dia entero, el motivo queda escrito y la pantalla puede decirle a
 * una persona que hay algo que revisar -- que es lo unico que lo desatasca.
 */
final class PasarelaPermanenteException extends PagoException
{
}
