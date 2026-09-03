<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use PDO;
use Throwable;

/**
 * Que puede AFIRMAR la pagina a la que vuelve el pagador desde la pasarela.
 *
 * ESTA CLASE NO CONFIRMA PAGOS. No escribe una sola fila. Lee el estado que ya
 * dejaron los dos unicos mecanismos autorizados a decidirlo:
 *
 *   Flow -> urlConfirmation -> ConfirmacionPago -> consultarEstado()   (el aviso)
 *   ReconciliadorPagos      -> ConfirmacionPago -> consultarEstado()   (el barrido)
 *
 * Los dos preguntan a la pasarela por su propio canal, con credenciales, y
 * comparan el monto. El navegador no participa en ninguno de los dos.
 *
 *
 * POR QUE EL NAVEGADOR NO PUEDE FABRICAR UN "PAGADO"
 * -----------------------------------------------------------------------------
 * Quien vuelve de pagar trae un token en el POST, y ese token es un dato del
 * navegador: cualquiera puede inventarse uno y mandarlo. Aqui se usa SOLO como
 * clave de busqueda para LEER una fila nuestra. El veredicto sale de la columna
 * estado, y esa columna solo la escribe ConfirmacionPago despues de preguntarle
 * a Flow. Un token inventado, en el mejor de los casos para el atacante, no
 * encuentra nada; y si encontrara algo, leeria un estado que el no puso.
 *
 * De ahi que el peor resultado posible de esta clase sea decir "verificando"
 * cuando ya estaba pagado. Nunca al reves.
 *
 *
 * POR QUE UN TOKEN DESCONOCIDO SE VE IGUAL QUE UNO PENDIENTE
 * -----------------------------------------------------------------------------
 * Es deliberado, y es lo que impide que la ruta sea un oraculo. Si el token que
 * no existe dijera "no encontrado" y el que existe dijera "verificando",
 * cualquiera podria probar tokens y averiguar CUALES son ordenes reales. Al
 * devolver lo mismo para los dos, un token al azar no distingue nada.
 *
 * Solo dos respuestas se apartan de la neutra -- confirmado y rechazado -- y las
 * dos exigen que la fila exista Y que la pasarela ya se haya pronunciado. Quien
 * tiene el token legitimo es el pagador: se lo acaba de dar Flow.
 *
 *
 * POR QUE SE BUSCA SIN cuenta_id, Y POR QUE ESO NO CRUZA TENANTS
 * -----------------------------------------------------------------------------
 * La url de confirmacion lleva la cuenta en el path y por eso ConfirmacionPago
 * puede acotar por ella. La de retorno NO: es una sola url publica, y el token
 * es lo unico que llega. Asi que aqui la busqueda es global.
 *
 * Dos defensas, porque una sola no basta:
 *
 *   1. NO SE DEVUELVE NINGUN DATO DE LA FILA. Ni monto, ni folio, ni rut, ni el
 *      nombre de la empresa. Solo una de tres palabras. Aunque el token de un
 *      tenant tocara la fila de otro, no hay nada que filtrar.
 *   2. SI EL TOKEN APARECE MAS DE UNA VEZ, se responde la neutra. Flow no
 *      promete que sus tokens sean unicos ENTRE comercios distintos, y ante una
 *      colision preferimos no afirmar nada antes que afirmar lo de otro.
 */
final class EstadoRetornoPago
{
    /** La pasarela ya confirmo el cobro por su canal. */
    public const CONFIRMADO = 'confirmado';

    /** La pasarela dijo que no hubo cobro (rechazado o anulado). */
    public const RECHAZADO = 'rechazado';

    /** Todo lo demas, incluido "no se sabe todavia". Es el valor por defecto. */
    public const VERIFICANDO = 'verificando';

    /**
     * Estados de la pasarela que significan "no se cobro".
     *
     * Los mismos que ReconciliadorPagos::ESTADOS_TERMINALES: si aquella lista
     * cambia, esta tiene que cambiar con ella o la pagina diria "verificando"
     * para siempre sobre algo que ya termino.
     */
    private const ESTADOS_FALLIDOS = ['rechazada', 'anulada'];

    /**
     * Forma aceptable de un token antes de que toque la base.
     *
     * No es validacion de seguridad -- la consulta va con parametro y no se
     * concatena nada --, es para no ir a la base por cada basura que llegue.
     * Ancho a proposito: el largo del token de Flow no esta documentado y
     * rechazar uno legitimo por estrecho dejaria al pagador sin su confirmacion.
     */
    private const PATRON_TOKEN = '/^[A-Za-z0-9._~-]{8,255}$/';

    /**
     * El token que trae la peticion, o null si no hay ninguno utilizable.
     *
     * POST PRIMERO PORQUE ES LO QUE HACE FLOW: su cliente PHP oficial lee
     * filter_input(INPUT_POST, 'token') en la pagina de retorno. GET queda como
     * red de seguridad, no como camino principal (ver el handler del router).
     *
     * Recibe los arrays en vez de leer las superglobales para que se pueda
     * probar sin montar una peticion.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $get
     */
    public static function tokenDeLaPeticion(array $post, array $get): ?string
    {
        foreach ([$post['token'] ?? null, $get['token'] ?? null] as $crudo) {
            if (! is_string($crudo)) {
                continue;   // un array en $_POST['token'] no es un token
            }
            $token = trim($crudo);
            if ($token !== '' && preg_match(self::PATRON_TOKEN, $token) === 1) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Una de las tres constantes de arriba. NUNCA escribe.
     *
     * Ante cualquier problema -- token ausente, token con forma rara, la base
     * caida -- devuelve VERIFICANDO. Es la respuesta honesta: no sabemos. Una
     * excepcion aqui pintaria un error PHP a un cliente que acaba de pagar, y
     * eso es peor que una pagina que pide esperar un momento.
     */
    public static function resolver(PDO $pdo, ?string $token): string
    {
        if ($token === null || preg_match(self::PATRON_TOKEN, $token) !== 1) {
            return self::VERIFICANDO;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT estado, estado_pasarela FROM dte_pago_link '
                . 'WHERE orden_externa = :t LIMIT 2'
            );
            $stmt->execute([':t' => $token]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return self::VERIFICANDO;
        }

        // Cero filas: token desconocido. Dos: colision entre comercios. Las dos
        // se responden igual que "todavia no se sabe" -- ver el docblock.
        if (count($filas) !== 1) {
            return self::VERIFICANDO;
        }

        $fila = $filas[0];

        // 'pagado' solo lo escribe ConfirmacionPago tras consultar a la pasarela
        // Y cuadrar el monto. Es la unica puerta a CONFIRMADO.
        if ((string) $fila['estado'] === 'pagado') {
            return self::CONFIRMADO;
        }

        $pasarela = strtolower(trim((string) ($fila['estado_pasarela'] ?? '')));
        if (in_array($pasarela, self::ESTADOS_FALLIDOS, true)) {
            return self::RECHAZADO;
        }

        return self::VERIFICANDO;
    }
}
