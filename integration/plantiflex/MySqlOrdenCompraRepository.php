<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Ordenes de compra (migraciones 037 y 038), con aislamiento FORZADO por
 * cuenta_id.
 *
 * UNA ORDEN DE COMPRA VA HACIA UN PROVEEDOR: es lo unico del sistema que apunta
 * para afuera en esa direccion. No toca el SII, no consume folio del CAF y no se
 * escribe en dte_emitido; este repositorio no conoce ni Ambiente ni TipoDte.
 */
final class MySqlOrdenCompraRepository
{
    /**
     * 19, y NO es un numero de esta clase: es el mismo TASA_IVA de
     * DteXmlBuilder. La regla del calculo tampoco se reinventa -- el IVA es
     * round(neto * 19 / 100) sobre el neto YA descontado, que es exactamente lo
     * que hace resolverTotales() cuando los montos vienen netos.
     */
    public const TASA_IVA = 19;

    private const COLUMNAS = 'id, numero, proveedor_id, proveedor_rut, proveedor_razon_social, '
        . 'proveedor_giro, proveedor_direccion, proveedor_comuna, proveedor_email, proveedor_contacto, '
        . 'condiciones_pago, fecha, fecha_entrega, lugar_entrega, notas, neto, exento, iva, total, '
        . 'activo, created_at, updated_at';

    private const COLUMNAS_LINEA = 'id, orden, producto_id, nombre, descripcion, unidad, '
        . 'cantidad, precio_unitario, descuento_pct, exento';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // =======================================================================
    //  CORRELATIVO -- copiado de MySqlCotizacionRepository::asignarNumero()
    // =======================================================================

    /**
     * Reserva y devuelve el siguiente numero de la cuenta.
     *
     * COPIA LITERAL DEL MECANISMO DE COTIZACION, y a proposito: ya se probo bajo
     * concurrencia con dos conexiones reales -- una espera el lock de la otra y
     * despues obtiene el numero SIGUIENTE. No es MAX(numero)+1: dos
     * transacciones leerian el mismo maximo antes de que ninguna insertara.
     *
     * DEBE LLAMARSE DENTRO DE UNA TRANSACCION YA ABIERTA (lo hace crear()): el
     * lock solo vale hasta el commit, asi que reservar el numero y escribir la
     * orden tienen que ser la misma transaccion. Si fueran dos, un fallo al
     * insertar dejaria un hueco en la numeracion.
     */
    public function asignarNumero(int $cuentaId): int
    {
        if (! $this->pdo->inTransaction()) {
            throw new RuntimeException(
                'asignarNumero() exige una transaccion abierta: el FOR UPDATE que impide que dos '
                . 'altas simultaneas se lleven el mismo numero solo vale hasta el commit.'
            );
        }

        $this->pdo->prepare('INSERT IGNORE INTO orden_compra_correlativo (cuenta_id, proximo) VALUES (?, 1)')
            ->execute([$cuentaId]);

        $sel = $this->pdo->prepare('SELECT proximo FROM orden_compra_correlativo WHERE cuenta_id = ? FOR UPDATE');
        $sel->execute([$cuentaId]);
        $proximo = $sel->fetchColumn();
        if ($proximo === false) {
            throw new RuntimeException("no se pudo reservar correlativo para la cuenta {$cuentaId}.");
        }

        $this->pdo->prepare('UPDATE orden_compra_correlativo SET proximo = proximo + 1 WHERE cuenta_id = ?')
            ->execute([$cuentaId]);

        return (int) $proximo;
    }

    // =======================================================================
    //  TOTALES
    // =======================================================================

    /**
     * Neto, exento, IVA y total de un conjunto de lineas.
     *
     * SE CALCULA UNA SOLA VEZ Y SE GUARDA. Una orden enviada es una foto: si el
     * precio del maestro cambia manana, la orden que el proveedor tiene en su
     * correo no cambia. Recalcular al mostrar haria que el PDF y el papel del
     * proveedor dejaran de coincidir sin que nadie tocara la orden.
     *
     * EL IVA VA SOLO SOBRE LO AFECTO, y se redondea UNA vez sobre el total
     * afecto -- no linea por linea. Sumar redondeos por linea da un peso de
     * diferencia con el total, que es el defecto clasico de esta cuenta.
     *
     * @param list<array<string,mixed>> $lineas
     * @return array{neto:int, exento:int, iva:int, total:int}
     */
    public static function totales(array $lineas): array
    {
        $afecto = 0.0;
        $exento = 0.0;
        foreach ($lineas as $l) {
            $bruto = (float) $l['cantidad'] * (float) $l['precio_unitario'];
            $neto  = $bruto * (1 - ((float) ($l['descuento_pct'] ?? 0)) / 100);
            if (! empty($l['exento'])) {
                $exento += $neto;
            } else {
                $afecto += $neto;
            }
        }

        $netoInt   = (int) round($afecto);
        $exentoInt = (int) round($exento);
        $iva       = (int) round($netoInt * self::TASA_IVA / 100);

        return [
            'neto'   => $netoInt,
            'exento' => $exentoInt,
            'iva'    => $iva,
            'total'  => $netoInt + $exentoInt + $iva,
        ];
    }

    // =======================================================================
    //  LECTURA
    // =======================================================================

    /** @return array<string,mixed>|null Cabecera + 'lineas'. */
    public function buscarPorId(int $cuentaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNAS . ' FROM orden_compra WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);
        $oc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($oc === false) {
            return null;
        }
        $oc['lineas'] = $this->lineasDe($id);

        return $oc;
    }

    /** @return list<array<string,mixed>> */
    public function lineasDe(int $ordenCompraId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS_LINEA . ' FROM orden_compra_linea '
            . 'WHERE orden_compra_id = ? ORDER BY orden ASC'
        );
        $stmt->execute([$ordenCompraId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function listar(
        int $cuentaId,
        ?string $busqueda = null,
        bool $soloActivas = true,
        int $limit = 25,
        int $offset = 0,
    ): array {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivas);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM orden_compra ' . $where
            . ' ORDER BY numero DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(int $cuentaId, ?string $busqueda = null, bool $soloActivas = true): int
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivas);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orden_compra ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function filtro(int $cuentaId, ?string $busqueda, bool $soloActivas): array
    {
        $where  = 'WHERE cuenta_id = ?';
        $params = [$cuentaId];
        if ($soloActivas) {
            $where .= ' AND activo = 1';
        }
        if ($busqueda !== null && trim($busqueda) !== '') {
            $like = '%' . trim($busqueda) . '%';
            $where .= ' AND (proveedor_razon_social LIKE ? OR proveedor_rut LIKE ? OR CAST(numero AS CHAR) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    // =======================================================================
    //  ESCRITURA
    // =======================================================================

    /**
     * Crea la orden con sus lineas y sus totales. Devuelve [id, numero].
     *
     * TODO EN UNA TRANSACCION: correlativo, cabecera y lineas. Si algo falla no
     * queda ni un hueco en la numeracion ni una cabecera sin lineas.
     *
     * @param array<string,mixed>       $datos
     * @param list<array<string,mixed>> $lineas
     * @return array{0:int, 1:int}
     */
    public function crear(int $cuentaId, array $datos, array $lineas): array
    {
        if ($lineas === []) {
            throw new RuntimeException('una orden de compra necesita al menos una linea.');
        }

        $this->pdo->beginTransaction();
        try {
            $numero = $this->asignarNumero($cuentaId);
            $t      = self::totales($lineas);

            $stmt = $this->pdo->prepare(
                'INSERT INTO orden_compra (cuenta_id, numero, proveedor_id, proveedor_rut, '
                . ' proveedor_razon_social, proveedor_giro, proveedor_direccion, proveedor_comuna, '
                . ' proveedor_email, proveedor_contacto, condiciones_pago, fecha, fecha_entrega, '
                . ' lugar_entrega, notas, neto, exento, iva, total) '
                . 'VALUES (:cuenta, :numero, :provId, :rut, :razon, :giro, :dir, :comuna, :email, '
                . ' :contacto, :cond, :fecha, :entrega, :lugar, :notas, :neto, :exento, :iva, :total)'
            );
            $stmt->execute($this->paramsCabecera($cuentaId, $numero, $datos) + [
                'neto' => $t['neto'], 'exento' => $t['exento'], 'iva' => $t['iva'], 'total' => $t['total'],
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $this->insertarLineas($id, $lineas);
            $this->pdo->commit();

            return [$id, $numero];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reemplaza cabecera y lineas, y recalcula los totales.
     *
     * SE PUEDE EDITAR DESPUES DE ENVIADA, Y ES DELIBERADO: no hay estados de
     * seguimiento, asi que no hay nada que "cerrar" una orden. Lo que SI queda es
     * rastro de lo que se mando: cada envio deja su fila en orden_compra_envio
     * con su destinatario y su fecha, y esa fila NO se toca al editar. Editar
     * cambia la orden de hoy, no reescribe el correo que el proveedor ya recibio.
     *
     * @param array<string,mixed>       $datos
     * @param list<array<string,mixed>> $lineas
     */
    public function actualizar(int $cuentaId, int $id, array $datos, array $lineas): void
    {
        if ($lineas === []) {
            throw new RuntimeException('una orden de compra necesita al menos una linea.');
        }

        $this->pdo->beginTransaction();
        try {
            $t = self::totales($lineas);

            $stmt = $this->pdo->prepare(
                'UPDATE orden_compra SET proveedor_id = :provId, proveedor_rut = :rut, '
                . ' proveedor_razon_social = :razon, proveedor_giro = :giro, proveedor_direccion = :dir, '
                . ' proveedor_comuna = :comuna, proveedor_email = :email, proveedor_contacto = :contacto, '
                . ' condiciones_pago = :cond, fecha = :fecha, fecha_entrega = :entrega, '
                . ' lugar_entrega = :lugar, notas = :notas, neto = :neto, exento = :exento, '
                . ' iva = :iva, total = :total '
                . 'WHERE cuenta_id = :cuenta AND id = :id'
            );
            $params = $this->paramsCabecera($cuentaId, null, $datos);
            unset($params['numero']);   // el correlativo NO se edita jamas
            $stmt->execute($params + [
                'id' => $id, 'neto' => $t['neto'], 'exento' => $t['exento'],
                'iva' => $t['iva'], 'total' => $t['total'],
            ]);

            $this->pdo->prepare('DELETE FROM orden_compra_linea WHERE orden_compra_id = ?')->execute([$id]);
            $this->insertarLineas($id, $lineas);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function desactivar(int $cuentaId, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE orden_compra SET activo = 0 WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);

        return $stmt->rowCount() > 0;
    }

    // =======================================================================
    //  COLA DE CORREO (migracion 038)
    // =======================================================================

    /**
     * Encola el envio de una orden. Devuelve el numero de intento encolado.
     *
     * REENVIAR ES LEGITIMO -- el proveedor perdio el correo, cambio la direccion
     * --, cosa que un DTE no necesita. Por eso el UNIQUE de la cola es
     * (orden_compra_id, intento_de) y no solo la orden: cada reenvio es una fila
     * nueva con su propio destinatario, y el historial de lo que se mando queda.
     *
     * ENCOLAR NUNCA PUEDE ROMPER EL GUARDADO DE LA ORDEN. Es la misma regla que
     * EncoladorCorreo enuncia para la emision: si esto falla, la orden ya existe
     * y el usuario tiene que verla guardada. Por eso el llamador envuelve, y por
     * eso este metodo no forma parte de la transaccion de crear().
     */
    public function encolarEnvio(int $cuentaId, int $ordenCompraId, ?string $destinatario): int
    {
        $sel = $this->pdo->prepare(
            'SELECT COALESCE(MAX(intento_de), 0) + 1 FROM orden_compra_envio WHERE orden_compra_id = ?'
        );
        $sel->execute([$ordenCompraId]);
        $intento = (int) $sel->fetchColumn();

        $dest = trim((string) ($destinatario ?? ''));
        $dest = $dest !== '' && filter_var($dest, FILTER_VALIDATE_EMAIL) ? $dest : null;

        $this->pdo->prepare(
            'INSERT INTO orden_compra_envio (orden_compra_id, cuenta_id, intento_de, destinatario, estado) '
            . 'VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $ordenCompraId, $cuentaId, $intento, $dest,
            $dest === null ? 'sin_destinatario' : 'pendiente',
        ]);

        return $intento;
    }

    /**
     * Registra el desenlace de un envio. Lo llama el runner.
     *
     * OJO CON QUE SIGNIFICA 'enviado': "BREVO ACEPTO EL MENSAJE", NO "el
     * proveedor lo recibio". Un destinatario en la lista de bloqueo de Brevo
     * devuelve 2xx y el correo nunca se entrega. Por eso se guarda el
     * $messageId: es lo unico que convierte un "no me llego" en una busqueda
     * exacta en el panel de Brevo. Misma advertencia que dejo PreparadorEnvio
     * para el camino del DTE.
     *
     * NO TOCA intentos: eso lo lleva el runner, que lo incrementa ANTES de
     * intentar para que un proceso muerto a mitad no deje la fila en un bucle.
     */
    public function marcarEnvio(int $envioId, string $estado, ?string $messageId, ?string $error): void
    {
        $this->pdo->prepare(
            'UPDATE orden_compra_envio SET estado = ?, message_id = ?, error_mensaje = ? WHERE id = ?'
        )->execute([$estado, $messageId, $error !== null ? mb_substr($error, 0, 500) : null, $envioId]);
    }

    /** @return list<array<string,mixed>> Envios de una orden, del mas nuevo al mas viejo. */
    public function enviosDe(int $cuentaId, int $ordenCompraId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.intento_de, e.destinatario, e.estado, e.intentos, e.message_id, '
            . '       e.error_mensaje, e.created_at, e.updated_at '
            . 'FROM orden_compra_envio e WHERE e.cuenta_id = ? AND e.orden_compra_id = ? '
            . 'ORDER BY e.intento_de DESC'
        );
        $stmt->execute([$cuentaId, $ordenCompraId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =======================================================================
    //  AUXILIARES
    // =======================================================================

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function paramsCabecera(int $cuentaId, ?int $numero, array $datos): array
    {
        return [
            'cuenta'   => $cuentaId,
            'numero'   => $numero,
            'provId'   => $datos['proveedor_id'] ?? null,
            'rut'      => $datos['proveedor_rut'],
            'razon'    => $datos['proveedor_razon_social'],
            'giro'     => $this->oNull($datos['proveedor_giro'] ?? null),
            'dir'      => $this->oNull($datos['proveedor_direccion'] ?? null),
            'comuna'   => $this->oNull($datos['proveedor_comuna'] ?? null),
            'email'    => $this->oNull($datos['proveedor_email'] ?? null),
            'contacto' => $this->oNull($datos['proveedor_contacto'] ?? null),
            'cond'     => $this->oNull($datos['condiciones_pago'] ?? null),
            'fecha'    => $datos['fecha'],
            'entrega'  => $this->oNull($datos['fecha_entrega'] ?? null),
            'lugar'    => $this->oNull($datos['lugar_entrega'] ?? null),
            'notas'    => $this->oNull($datos['notas'] ?? null),
        ];
    }

    /** @param list<array<string,mixed>> $lineas */
    private function insertarLineas(int $ordenCompraId, array $lineas): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orden_compra_linea (orden_compra_id, orden, producto_id, nombre, descripcion, '
            . ' unidad, cantidad, precio_unitario, descuento_pct, exento) '
            . 'VALUES (:oc, :orden, :prod, :nombre, :desc, :unidad, :cant, :precio, :dpct, :exento)'
        );
        $orden = 0;
        foreach ($lineas as $l) {
            $orden++;
            $stmt->execute([
                'oc'     => $ordenCompraId,
                'orden'  => $orden,
                'prod'   => $l['producto_id'] ?? null,
                'nombre' => $l['nombre'],
                'desc'   => $this->oNull($l['descripcion'] ?? null),
                'unidad' => $this->oNull($l['unidad'] ?? null),
                'cant'   => $l['cantidad'],
                'precio' => $l['precio_unitario'],
                'dpct'   => $l['descuento_pct'] ?? 0,
                'exento' => ! empty($l['exento']) ? 1 : 0,
            ]);
        }
    }

    private function oNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
