<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;

/**
 * Tope de consultas del chat por cuenta y por dia (migracion 034).
 *
 * =============================================================================
 * POR QUE 30, Y NO 5 NI 500
 * =============================================================================
 *
 * El tope no existe para racionar al usuario: existe para que un bucle o un rato
 * de curiosidad no se lleven saldo real. Los dos numeros que lo acotan:
 *
 *   POR ARRIBA: una pregunta cuesta del orden de una milesima de dolar
 *   (deepseek-chat, ~600 tokens de entrada y ~100 de salida). 30 preguntas
 *   diarias por cuenta son centavos al mes por cuenta, incluso con todas las
 *   cuentas al maximo todos los dias. Es un techo que se puede pagar sin mirar.
 *
 *   POR ABAJO: 30 preguntas en un dia ya es un caso raro. El uso normal son los
 *   SEIS INFORMES del menu, que responden gratis lo que se pregunta a diario --
 *   el chat es para lo que esos informes no combinan. Un usuario honesto no lo
 *   toca; un script si.
 *
 * SE PODRA SUBIR CUANDO haya un mes de uso medido: hoy no hay ni un dato de
 * cuantas preguntas hace una cuenta de verdad, asi que 30 es una apuesta
 * conservadora y no una medicion. Cambiar la constante es todo lo que hace falta.
 *
 * =============================================================================
 * QUE SE CUENTA, Y CUANDO
 * =============================================================================
 *
 * SE CUENTAN LAS LLAMADAS AL PROVEEDOR, no las respuestas utiles: una pregunta
 * que el modelo no entendio igual costo. Lo unico que no descuenta es lo que
 * nunca salio -- sin clave configurada no hay llamada y no hay gasto.
 *
 * EL ORDEN ES: mirar el contador, y solo si queda cupo llamar. Asi la pregunta
 * N+1 NO llega al proveedor, que es el punto entero del tope. La ventana de
 * carrera entre mirar y sumar existe y se acepta: dos peticiones simultaneas al
 * borde del tope pueden pasar las dos, y el costo de eso es UNA consulta de mas.
 * Cerrarla con un lock por cuenta serializaria el chat entero para ahorrar una
 * milesima de dolar.
 */
final class MySqlChatUsoRepository
{
    /** Ver el docblock: apuesta conservadora, no medicion. */
    public const LIMITE_DIARIO = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Consultas ya hechas hoy por esta cuenta. */
    public function consultasDeHoy(int $cuentaId, string $hoy): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT consultas FROM chat_consulta_uso WHERE cuenta_id = ? AND dia = ?'
        );
        $stmt->execute([$cuentaId, $hoy]);
        $n = $stmt->fetchColumn();

        return $n === false ? 0 : (int) $n;
    }

    public function quedaCupo(int $cuentaId, string $hoy): bool
    {
        return $this->consultasDeHoy($cuentaId, $hoy) < self::LIMITE_DIARIO;
    }

    /**
     * Suma una consulta. SE LLAMA DESPUES DE INTENTAR LA LLAMADA AL PROVEEDOR,
     * no antes: lo que se cobra es la llamada, y una que no llego a salir no
     * tiene que descontar cupo.
     */
    public function registrarConsulta(int $cuentaId, string $hoy): void
    {
        $this->pdo->prepare(
            'INSERT INTO chat_consulta_uso (cuenta_id, dia, consultas) VALUES (?, ?, 1) '
            . 'ON DUPLICATE KEY UPDATE consultas = consultas + 1'
        )->execute([$cuentaId, $hoy]);
    }

    // =======================================================================
    //  HISTORIAL (migracion 035)
    // =======================================================================

    /** Cuantas preguntas muestra la tarjeta de actividad reciente. */
    public const RECIENTES = 5;

    /**
     * Guarda la pregunta. VA APARTE DEL CONTADOR a proposito: el contador cuenta
     * llamadas al proveedor -- lo que cuesta dinero -- y esto guarda lo que el
     * usuario escribio, que es otra cosa y tiene otro motivo. Una pregunta que
     * no llego a salir (sin clave) no descuenta cupo pero SI se guarda: para el
     * usuario, preguntar y que el sistema no este configurado sigue siendo algo
     * que hizo.
     *
     * $desenlace: respondida | imposible | no_entendida | error.
     */
    public function registrarPregunta(int $cuentaId, ?int $usuarioId, string $pregunta, string $desenlace): void
    {
        $this->pdo->prepare(
            'INSERT INTO chat_consulta (cuenta_id, usuario_id, pregunta, desenlace) VALUES (?, ?, ?, ?)'
        )->execute([
            $cuentaId,
            $usuarioId,
            // La columna es VARCHAR(500) y el formulario limita a 500; se corta
            // igual por si la peticion no vino del formulario.
            mb_substr(trim($pregunta), 0, 500),
            $desenlace,
        ]);
    }

    /**
     * Las ultimas preguntas de ESTA cuenta. El cuenta_id va en el WHERE, como en
     * todo repositorio del anfitrion: la actividad de una empresa no puede
     * asomarse en la pantalla de otra.
     *
     * @return list<array{pregunta:string, desenlace:string, created_at:string}>
     */
    public function recientes(int $cuentaId, int $cuantas = self::RECIENTES): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pregunta, desenlace, created_at FROM chat_consulta '
            . 'WHERE cuenta_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $cuantas)
        );
        $stmt->execute([$cuentaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
