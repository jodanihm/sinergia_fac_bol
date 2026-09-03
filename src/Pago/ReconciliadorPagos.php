<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use Closure;
use GuzzleHttp\Client;
use PDO;
use Throwable;

/**
 * Le pregunta a la pasarela, por nuestra cuenta, que paso con las ordenes de
 * cobro que siguen sin resolverse.
 *
 * POR QUE EXISTE: LA PASARELA NO REINTENTA
 * -----------------------------------------------------------------------------
 * Flow llama UNA VEZ a la url de confirmacion, espera un 200 en menos de 15
 * segundos y, si no lo recibe, manda un correo de "Alerta: Problema de
 * integracion" -- pero no vuelve a llamar. Y su documentacion aclara que el
 * estado de la transaccion no se ve afectado por ese error: el pago sigue
 * cobrado de su lado.
 *
 * O sea que si el aviso se pierde -- red, un despliegue justo en ese segundo,
 * la consulta de estado caida, la fila todavia sin guardar -- ese pago es dinero
 * cobrado que nunca aparece en nuestro sistema, y nada lo traeria de vuelta.
 *
 * El diseno anterior respondia 503 "para que la pasarela reintente". Esa
 * suposicion era falsa, y dejaba la recuperacion apoyada en algo que no ocurre.
 *
 *
 * BARRE POR ESTADO, NO POR LA MARCA, Y ESA ES LA DECISION QUE IMPORTA
 * -----------------------------------------------------------------------------
 * Lo evidente seria mirar solo las filas con confirmacion_pendiente_at puesta.
 * No alcanza: el peor caso NO DEJA MARCA. Si el aviso no llego nunca, no hubo
 * quien marcara nada, y esa orden pagada seria invisible para siempre.
 *
 * Por eso el criterio es "toda orden creada que aun no sabemos si se pago". La
 * marca sirve para PRIORIZAR, no para filtrar.
 *
 *
 * NO CREA ORDENES, NO MANDA CORREOS, NO MUEVE DINERO
 * -----------------------------------------------------------------------------
 * Solo pregunta y anota. Es deliberado: un proceso que corre solo, cada cierto
 * tiempo y sin nadie mirando, tiene que poder hacer como maximo una cosa
 * inofensiva. Crear cobros o mandar correos desde aqui convertiria un fallo de
 * este bucle en un incidente con clientes.
 *
 * Y NO DUPLICA LA LOGICA DEL AVISO: llama a ConfirmacionPago::resolverOrden(),
 * la misma que usa el callback. Si la regla del monto o la del estado se
 * arreglaran en un sitio y no en el otro, el sistema daria respuestas distintas
 * segun por donde llegara la noticia, y una de las dos seria la equivocada.
 */
final class ReconciliadorPagos
{
    /**
     * Espera creciente entre preguntas por una orden que sigue sin resolverse,
     * en minutos, segun cuantas veces se ha preguntado ya.
     *
     * PASADO EL ULTIMO TRAMO NO SE DEJA DE MIRAR: se pasa a la cadencia de
     * mantenimiento, y ahi se queda para siempre.
     *
     * ESA DIFERENCIA ARREGLA UN FALLO DEMOSTRADO. La version anterior excluia la
     * orden al llegar a 20 intentos, y ese presupuesto lo gastaba el barrido
     * NORMAL de facturas impagadas, no los errores. Consecuencia: una factura
     * impagada dos semanas agotaba sus consultas; si el cliente pagaba el dia 20
     * y el aviso de la pasarela fallaba, esa orden quedaba fuera del sistema PARA
     * SIEMPRE. Dinero cobrado que no aparecia nunca -- justo el fallo que este
     * conciliador existe para eliminar.
     */
    private const BACKOFF_MINUTOS = [5, 15, 60, 360, 1440];

    /**
     * Cadencia de mantenimiento: una vez por semana, sin fin.
     *
     * El coste de no apagar nunca una orden es bajo -- una consulta semanal por
     * orden viva, con tope global por corrida -- y el coste de apagarla ya se
     * midio: se pierde un cobro entero.
     */
    private const MANTENIMIENTO_MINUTOS = 10080;

    /**
     * Espera para las ordenes que dejaron un AVISO SIN RESOLVER.
     *
     * De esas SI sabemos que la pasarela intento decirnos algo, asi que nunca
     * bajan a mantenimiento: se quedan en una hora como maximo. Son pocas por
     * definicion y cada una representa, probablemente, dinero ya cobrado.
     */
    private const BACKOFF_AVISO_MINUTOS = [5, 15, 60];

    /**
     * Estados en los que la pasarela ya dio la orden por terminada.
     *
     * Pasan directas a mantenimiento en vez de dejar de mirarse. Es conservador a
     * proposito: NO hemos verificado si una orden rechazada puede pagarse mas
     * tarde con el mismo link. Mientras no se sepa, una consulta semanal cuesta
     * casi nada y apagarlas del todo podria dejarnos ciegos otra vez.
     */
    private const ESTADOS_TERMINALES = ['rechazada', 'anulada'];

    /** Cuantas ordenes se miran por corrida, para no alargarla sin limite. */
    private const TOPE_POR_CORRIDA = 100;

    /**
     * @param Closure $descifrar (string $cifrado): string
     *
     * @return array{miradas:int, pagadas:int, sin_pagar:int, descuadres:int, fallidas:int}
     */
    public static function conciliar(
        PDO $pdo,
        Closure $descifrar,
        ?Client $http = null,
        int $tope = self::TOPE_POR_CORRIDA,
    ): array {
        $resumen = ['miradas' => 0, 'pagadas' => 0, 'sin_pagar' => 0, 'descuadres' => 0, 'fallidas' => 0];

        foreach (self::candidatas($pdo, $tope) as $fila) {
            $resumen['miradas']++;

            // El intento se anota ANTES de preguntar, no despues. Si el proceso
            // muriera a mitad de la llamada, la orden no quedaria elegible otra
            // vez de inmediato: sin esto, una pasarela que cuelga produciria un
            // bucle de corridas que empiezan siempre por la misma fila.
            self::anotarIntento($pdo, (int) $fila['id']);

            try {
                $desenlace = ConfirmacionPago::resolverOrden(
                    $pdo,
                    [
                        'proveedor'          => $fila['proveedor'],
                        'ambiente'           => $fila['ambiente'],
                        'credencial_publica' => $fila['credencial_publica'],
                    ],
                    // EL SECRETO DE SU PROPIA CUENTA. La consulta de candidatas
                    // trae las credenciales unidas por cuenta_id, asi que aqui no
                    // hay forma de mezclar el secreto de un tenant con la orden de
                    // otro: viajan en la misma fila.
                    $descifrar((string) $fila['credencial_cifrada']),
                    (int) $fila['id'],
                    (int) $fila['monto'],
                    (string) $fila['orden_externa'],
                    $http
                );
            } catch (Throwable $e) {
                // Credenciales ilegibles, proveedor desconocido, lo que sea de
                // ESTA fila: se anota y se sigue con la siguiente. Una fila rota
                // no puede llevarse por delante la corrida entera.
                $resumen['fallidas']++;
                self::anotarError($pdo, (int) $fila['id'], $e->getMessage());
                continue;
            }

            match ($desenlace['cambio']) {
                'pagado'         => $resumen['pagadas']++,
                'no_pagado'      => $resumen['sin_pagar']++,
                'monto_distinto' => $resumen['descuadres']++,
                default          => $resumen['fallidas']++,
            };
        }

        return $resumen;
    }

    /**
     * Las ordenes que aun no sabemos si se pagaron y que ya toca volver a mirar.
     *
     * SOLO 'creado'. Una orden 'pagado' esta cerrada; una 'omitido' nunca llego a
     * existir en la pasarela; y una 'error' -- descuadre de monto -- espera a una
     * PERSONA, no a otra consulta: volver a preguntar por ella daria el mismo
     * resultado y taparia el incidente bajo un intento mas.
     *
     * El JOIN trae las credenciales de la MISMA cuenta de la orden. Es lo que
     * hace imposible, por construccion, usar el secreto de un tenant contra la
     * orden de otro.
     *
     * @return list<array<string,mixed>>
     */
    private static function candidatas(PDO $pdo, int $tope): array
    {
        // LA ESPERA SE EXPRESA EN EL WHERE, no filtrando en PHP lo que ya vino.
        // Si se filtrara despues, el LIMIT contaria filas que van a descartarse y
        // una corrida podria volver de vacio teniendo trabajo pendiente detras.
        //
        // Marcas de tiempo YA CALCULADAS en PHP: nada de DATE_SUB ni de INTERVAL,
        // que no existen en SQLite y dejarian esta consulta sin poder probarse.
        //
        // TRES CADENCIAS, y el orden de los OR es el orden de prioridad:
        //
        //   1. aviso sin resolver  -> rapida y con tope de 1 h, nunca mas lenta
        //   2. terminada alla      -> mantenimiento (semanal)
        //   3. el resto            -> progresiva y luego mantenimiento
        //
        // NINGUNA RAMA EXCLUYE UNA ORDEN PARA SIEMPRE. Ese era el fallo anterior.
        $ahora = time();
        $liga  = [];

        $marca = static function (string $clave, int $minutos) use (&$liga, $ahora): string {
            $liga[$clave] = date('Y-m-d H:i:s', $ahora - ($minutos * 60));

            return $clave;
        };

        // 1. Con aviso sin resolver.
        $ramaAviso = [];
        $ultimoAv  = count(self::BACKOFF_AVISO_MINUTOS) - 1;
        foreach (self::BACKOFF_AVISO_MINUTOS as $i => $min) {
            $ramaAviso[] = sprintf(
                '(p.conciliacion_intentos %s %d AND p.conciliacion_ultimo_intento_at < %s)',
                $i === $ultimoAv ? '>=' : '=',
                $i,
                $marca(':av' . $i, $min)
            );
        }
        $rama1 = '(p.confirmacion_pendiente_at IS NOT NULL AND (' . implode(' OR ', $ramaAviso) . '))';

        // 2. Terminada segun la pasarela: solo mantenimiento.
        $marcasTerm = [];
        foreach (self::ESTADOS_TERMINALES as $j => $terminal) {
            $liga[':term' . $j] = $terminal;
            $marcasTerm[]       = ':term' . $j;
        }
        $rama2 = sprintf(
            '(p.confirmacion_pendiente_at IS NULL AND p.estado_pasarela IN (%s) AND p.conciliacion_ultimo_intento_at < %s)',
            implode(', ', $marcasTerm),
            $marca(':mant', self::MANTENIMIENTO_MINUTOS)
        );

        // 3. El resto: progresiva y, agotada, mantenimiento.
        $ramaNormal = [];
        $ultimoN    = count(self::BACKOFF_MINUTOS) - 1;
        foreach (self::BACKOFF_MINUTOS as $i => $min) {
            $ramaNormal[] = sprintf(
                '(p.conciliacion_intentos = %d AND p.conciliacion_ultimo_intento_at < %s)',
                $i,
                $marca(':n' . $i, $min)
            );
        }
        $ramaNormal[] = sprintf(
            '(p.conciliacion_intentos > %d AND p.conciliacion_ultimo_intento_at < %s)',
            $ultimoN,
            ':mant'
        );
        $rama3 = sprintf(
            '(p.confirmacion_pendiente_at IS NULL AND (p.estado_pasarela IS NULL OR p.estado_pasarela NOT IN (%s)) AND (%s))',
            implode(', ', $marcasTerm),
            implode(' OR ', $ramaNormal)
        );

        $sql = 'SELECT p.id, p.monto, p.orden_externa, p.conciliacion_intentos, '
            . '       p.proveedor, p.ambiente, cr.credencial_publica, cr.credencial_cifrada '
            . 'FROM dte_pago_link p '
            // EL JOIN VA POR LA HISTORIA DE LA ORDEN, NO POR LA ELECCION ACTIVA.
            //
            // cuenta_id sigue haciendo imposible, por construccion, usar el
            // secreto de un tenant contra la orden de otro: viajan en la misma
            // fila. Pero ahora ademas proveedor y ambiente salen de p, o sea de
            // lo que se congelo al crear la orden. Antes se tomaban de la
            // configuracion vigente, y en cuanto una empresa pasaba a produccion
            // sus ordenes vivas de sandbox se consultaban contra el Flow real
            // con un token que alli no existe: fallo permanente, cada cinco
            // minutos, para siempre.
            //
            // Y AL SER INNER, una orden cuyo ambiente ya no tiene llaves
            // cargadas sale del barrido en vez de fallar en bucle. Es el
            // comportamiento que se queria y sale gratis.
            . 'INNER JOIN pago_pasarela_credencial cr '
            . '        ON cr.cuenta_id = p.cuenta_id '
            . '       AND cr.proveedor = p.proveedor '
            . '       AND cr.ambiente  = p.ambiente '
            . "WHERE p.estado = 'creado' "
            . '  AND p.orden_externa IS NOT NULL '
            . '  AND (p.conciliacion_ultimo_intento_at IS NULL '
            . '       OR ' . $rama1 . ' OR ' . $rama2 . ' OR ' . $rama3 . ') '
            // Las que dejaron aviso sin resolver van primero: de esas SI sabemos
            // que hubo movimiento.
            . 'ORDER BY (p.confirmacion_pendiente_at IS NOT NULL) DESC, p.id ASC '
            . 'LIMIT :lim';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $tope, PDO::PARAM_INT);
        foreach ($liga as $clave => $valor) {
            $stmt->bindValue($clave, $valor);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function anotarIntento(PDO $pdo, int $linkId): void
    {
        $pdo->prepare(
            'UPDATE dte_pago_link SET conciliacion_intentos = conciliacion_intentos + 1, '
            . 'conciliacion_ultimo_intento_at = :ahora WHERE id = :id'
        )->execute([':ahora' => date('Y-m-d H:i:s'), ':id' => $linkId]);
    }

    private static function anotarError(PDO $pdo, int $linkId, string $motivo): void
    {
        try {
            $pdo->prepare('UPDATE dte_pago_link SET ultimo_error = :e WHERE id = :id')
                ->execute([':e' => mb_substr($motivo, 0, 500), ':id' => $linkId]);
        } catch (Throwable $e) {
            // Si ni anotar se puede, la corrida siguiente lo volvera a intentar.
        }
    }
}
