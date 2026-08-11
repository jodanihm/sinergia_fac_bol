<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Cotizaciones (tablas cotizacion, cotizacion_linea, cotizacion_correlativo) con
 * aislamiento FORZADO por cuenta_id.
 *
 * PERTENECE AL SISTEMA ANFITRION (panel SaaS), no al paquete agnostico del
 * motor: vive en integration/plantiflex/ y trabaja con arrays asociativos, mismo
 * patron que MySqlClienteRepository y MySqlDteEmitidoRepository.
 *
 * UNA COTIZACION NO ES UN DTE: no toca el SII, no consume folio del CAF y no se
 * escribe en dte_emitido. Este repositorio no conoce ni Ambiente ni TipoDte.
 *
 * REGLA DE AISLAMIENTO: cuenta_id es el PRIMER parametro de todo metodo que toque
 * datos y SIEMPRE va en el WHERE. buscarPorId() devuelve null tanto si no existe
 * como si es de otra cuenta -- nunca distingue los dos casos.
 */
final class MySqlCotizacionRepository
{
    /** Columnas de cabecera que se exponen al anfitrion. */
    private const COLUMNAS = 'id, numero, cliente_id, receptor_rut, receptor_razon_social, '
        . 'receptor_giro, receptor_direccion, receptor_comuna, receptor_email, fecha, '
        . 'valida_hasta, notas, estado_cache, activo, created_at, updated_at';

    /** Columnas de linea. `id` es EL VINCULO con la factura parcial. */
    private const COLUMNAS_LINEA = 'id, orden, producto_id, nombre, descripcion, unidad, '
        . 'cantidad, cantidad_facturada, precio_unitario, descuento_pct, exento';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // =======================================================================
    //  CORRELATIVO
    // =======================================================================

    /**
     * Reserva y devuelve el siguiente numero de la cuenta.
     *
     * MISMA CAUTELA QUE LOS FOLIOS, Y POR EL MISMO MOTIVO. No es MAX(numero)+1:
     * dos altas simultaneas leerian el mismo maximo antes de que ninguna
     * insertara y se llevarian el mismo numero. Se serializa con
     * SELECT ... FOR UPDATE sobre la fila del contador, que es el mecanismo de
     * MySqlFolioRepository::asignarSiguienteFolio().
     *
     * DEBE LLAMARSE DENTRO DE UNA TRANSACCION YA ABIERTA (lo hace crear()): el
     * lock solo vale hasta el commit, asi que reservar el numero y escribir la
     * cotizacion tienen que ser la misma transaccion. Si se hicieran en dos, un
     * fallo al insertar dejaria un hueco en la numeracion.
     *
     * La fila del contador se crea al vuelo la primera vez (INSERT IGNORE) para
     * no obligar a sembrar nada al dar de alta una cuenta.
     */
    public function asignarNumero(int $cuentaId): int
    {
        if (! $this->pdo->inTransaction()) {
            throw new RuntimeException(
                'asignarNumero() exige una transaccion abierta: el FOR UPDATE que impide '
                . 'que dos altas simultaneas se lleven el mismo numero solo vale hasta el commit.'
            );
        }

        // Sin ON DUPLICATE KEY UPDATE: no hay nada que actualizar y un UPDATE
        // vacio tomaria el lock igual, con mas ruido.
        $this->pdo->prepare('INSERT IGNORE INTO cotizacion_correlativo (cuenta_id, proximo) VALUES (?, 1)')
            ->execute([$cuentaId]);

        $sel = $this->pdo->prepare(
            'SELECT proximo FROM cotizacion_correlativo WHERE cuenta_id = ? FOR UPDATE'
        );
        $sel->execute([$cuentaId]);
        $proximo = $sel->fetchColumn();
        if ($proximo === false) {
            throw new RuntimeException("no se pudo reservar correlativo para la cuenta {$cuentaId}.");
        }

        $this->pdo->prepare('UPDATE cotizacion_correlativo SET proximo = proximo + 1 WHERE cuenta_id = ?')
            ->execute([$cuentaId]);

        return (int) $proximo;
    }

    // =======================================================================
    //  LECTURA
    // =======================================================================

    /** @return array<string,mixed>|null Cabecera + 'lineas'. null si no existe o es de otra cuenta. */
    public function buscarPorId(int $cuentaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM cotizacion WHERE cuenta_id = ? AND id = ?'
        );
        $stmt->execute([$cuentaId, $id]);
        $cot = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cot === false) {
            return null;
        }
        $cot['lineas'] = $this->lineasDe($id);

        return $cot;
    }

    /** @return list<array<string,mixed>> */
    public function lineasDe(int $cotizacionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS_LINEA . ' FROM cotizacion_linea '
            . 'WHERE cotizacion_id = ? ORDER BY orden ASC'
        );
        $stmt->execute([$cotizacionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>> Cabeceras (sin lineas) + 'total_estimado'.
     */
    public function listar(
        int $cuentaId,
        ?string $busqueda = null,
        bool $soloActivas = true,
        ?string $estado = null,
        int $limit = 25,
        int $offset = 0,
    ): array {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivas, $estado);

        // El total se agrega sobre las lineas en la MISMA consulta: no hay
        // columna cache de monto y no se quiere una por lo mismo que el estado --
        // el precio de la cotizacion es el cotizado y no cambia, pero agregarlo
        // aqui evita inventar un segundo cache que mantener.
        $sql = 'SELECT c.' . str_replace(', ', ', c.', self::COLUMNAS) . ', '
            . 'COALESCE((SELECT SUM(l.cantidad * l.precio_unitario * (1 - l.descuento_pct / 100)) '
            . '          FROM cotizacion_linea l WHERE l.cotizacion_id = c.id), 0) AS total_estimado '
            . 'FROM cotizacion c ' . $where
            . ' ORDER BY c.numero DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(
        int $cuentaId,
        ?string $busqueda = null,
        bool $soloActivas = true,
        ?string $estado = null,
    ): int {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivas, $estado);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cotizacion c ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function filtro(int $cuentaId, ?string $busqueda, bool $soloActivas, ?string $estado): array
    {
        $where  = 'WHERE c.cuenta_id = ?';
        $params = [$cuentaId];

        if ($soloActivas) {
            $where .= ' AND c.activo = 1';
        }
        // El filtro por estado usa estado_cache A PROPOSITO: es exactamente para
        // esto que la columna existe (ix_cotizacion_estado). Para AUTORIZAR una
        // edicion NO se usa nunca -- eso lo decide tieneFacturacion(), que mira
        // las cantidades.
        if ($estado !== null && in_array($estado, ['sin_facturar', 'parcial', 'facturada'], true)) {
            $where .= ' AND c.estado_cache = ?';
            $params[] = $estado;
        }
        if ($busqueda !== null && trim($busqueda) !== '') {
            $like = '%' . trim($busqueda) . '%';
            // El numero se busca tambien como texto para que "12" encuentre la 12.
            $where .= ' AND (c.receptor_razon_social LIKE ? OR c.receptor_rut LIKE ? OR CAST(c.numero AS CHAR) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    // =======================================================================
    //  EL SALDO
    // =======================================================================

    /**
     * ¿Esta cotizacion tiene ALGO facturado?
     *
     * ESTA ES LA CONSULTA QUE AUTORIZA LA EDICION, y mira las CANTIDADES, no
     * estado_cache. El cache es para listar con indice; si se desfasara, usarlo
     * aqui dejaria editar una cotizacion ya facturada y romperia el vinculo por
     * id de linea de las facturas emitidas.
     *
     * > 0 y no != 0: cantidad_facturada es DECIMAL y no puede ser negativa (lo
     * impide el CHECK de la migracion 032).
     */
    public function tieneFacturacion(int $cuentaId, int $cotizacionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(l.cantidad_facturada), 0) '
            . 'FROM cotizacion_linea l '
            . 'INNER JOIN cotizacion c ON c.id = l.cotizacion_id '
            . 'WHERE c.cuenta_id = ? AND l.cotizacion_id = ?'
        );
        $stmt->execute([$cuentaId, $cotizacionId]);

        return ((float) $stmt->fetchColumn()) > 0.0;
    }

    /**
     * Recalcula estado_cache DESDE LAS CANTIDADES y lo escribe.
     *
     * ES EL UNICO SITIO QUE ESCRIBE ESA COLUMNA. Ver la nota larga de la
     * migracion 032: quien la recalcula (esto) y cuando (al crear, al editar, y
     * en la segunda entrega dentro de la misma transaccion que incrementa
     * cantidad_facturada, DESPUES de que el motor confirmo).
     *
     * No necesita saber el valor anterior: lo reconstruye entero, asi que un
     * desfase se arregla llamando a esto y nunca editando la columna a mano.
     *
     * @return string el estado que quedo escrito
     */
    public function recalcularEstado(int $cuentaId, int $cotizacionId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(l.cantidad), 0) AS cant, '
            . '       COALESCE(SUM(l.cantidad_facturada), 0) AS fact, '
            . '       COALESCE(SUM(CASE WHEN l.cantidad_facturada < l.cantidad THEN 1 ELSE 0 END), 0) AS pendientes '
            . 'FROM cotizacion_linea l WHERE l.cotizacion_id = ?'
        );
        $stmt->execute([$cotizacionId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cant' => 0, 'fact' => 0, 'pendientes' => 0];

        $facturado = (float) $r['fact'];
        if ($facturado <= 0.0) {
            $estado = 'sin_facturar';
        } elseif ((int) $r['pendientes'] > 0) {
            $estado = 'parcial';
        } else {
            $estado = 'facturada';
        }

        $upd = $this->pdo->prepare(
            'UPDATE cotizacion SET estado_cache = ? WHERE cuenta_id = ? AND id = ?'
        );
        $upd->execute([$estado, $cuentaId, $cotizacionId]);

        return $estado;
    }

    // =======================================================================
    //  ESCRITURA
    // =======================================================================

    /**
     * Crea la cotizacion con sus lineas y devuelve [id, numero].
     *
     * TODO EN UNA TRANSACCION: la reserva del correlativo, la cabecera y las
     * lineas. Si algo falla, no queda ni un hueco en la numeracion ni una
     * cabecera sin lineas.
     *
     * @param array<string,mixed>            $datos
     * @param list<array<string,mixed>>      $lineas
     * @return array{0:int, 1:int}
     */
    public function crear(int $cuentaId, array $datos, array $lineas): array
    {
        if ($lineas === []) {
            throw new RuntimeException('una cotizacion necesita al menos una linea.');
        }

        $this->pdo->beginTransaction();
        try {
            $numero = $this->asignarNumero($cuentaId);

            $stmt = $this->pdo->prepare(
                'INSERT INTO cotizacion (cuenta_id, numero, cliente_id, receptor_rut, '
                . ' receptor_razon_social, receptor_giro, receptor_direccion, receptor_comuna, '
                . ' receptor_email, fecha, valida_hasta, notas) '
                . 'VALUES (:cuenta, :numero, :cliente, :rut, :razon, :giro, :dir, :comuna, '
                . ' :email, :fecha, :valida, :notas)'
            );
            $stmt->execute($this->paramsCabecera($cuentaId, $numero, $datos));
            $id = (int) $this->pdo->lastInsertId();

            $this->insertarLineas($id, $lineas);

            // Por construccion queda 'sin_facturar' (toda linea nace con
            // cantidad_facturada = 0), pero se llama igual: asi el estado NUNCA
            // lo escribe nadie que no sea recalcularEstado().
            $this->recalcularEstado($cuentaId, $id);

            $this->pdo->commit();

            return [$id, $numero];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reemplaza cabecera y lineas. SOLO si no hay nada facturado.
     *
     * POR QUE SE PUEDE BORRAR Y RECREAR LAS LINEAS AQUI: porque sin facturacion
     * no hay ninguna factura apuntando a esos id. En cuanto haya una sola
     * cantidad facturada, recrear las lineas cambiaria los id y dejaria a las
     * facturas emitidas apuntando a lineas que ya no existen -- por eso esto
     * lanza en vez de intentar un merge.
     *
     * LA COMPROBACION SE REPITE DENTRO DE LA TRANSACCION aunque el handler ya la
     * haya hecho: entre su chequeo y este UPDATE puede haberse emitido una
     * factura parcial. El SELECT ... FOR UPDATE sobre las lineas serializa las
     * dos operaciones.
     *
     * @param array<string,mixed>       $datos
     * @param list<array<string,mixed>> $lineas
     */
    public function actualizar(int $cuentaId, int $id, array $datos, array $lineas): void
    {
        if ($lineas === []) {
            throw new RuntimeException('una cotizacion necesita al menos una linea.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT l.id FROM cotizacion_linea l '
                . 'INNER JOIN cotizacion c ON c.id = l.cotizacion_id '
                . 'WHERE c.cuenta_id = ? AND l.cotizacion_id = ? FOR UPDATE'
            );
            $lock->execute([$cuentaId, $id]);

            if ($this->tieneFacturacion($cuentaId, $id)) {
                throw new CotizacionFacturadaException(
                    'La cotizacion ya tiene facturacion y no se puede editar: cambiar sus lineas '
                    . 'romperia el vinculo de las facturas ya emitidas.'
                );
            }

            $stmt = $this->pdo->prepare(
                'UPDATE cotizacion SET cliente_id = :cliente, receptor_rut = :rut, '
                . ' receptor_razon_social = :razon, receptor_giro = :giro, '
                . ' receptor_direccion = :dir, receptor_comuna = :comuna, receptor_email = :email, '
                . ' fecha = :fecha, valida_hasta = :valida, notas = :notas '
                . 'WHERE cuenta_id = :cuenta AND id = :id'
            );
            $params = $this->paramsCabecera($cuentaId, null, $datos);
            unset($params['numero']);       // el correlativo NO se edita jamas
            $params['id'] = $id;
            $stmt->execute($params);

            $this->pdo->prepare('DELETE FROM cotizacion_linea WHERE cotizacion_id = ?')->execute([$id]);
            $this->insertarLineas($id, $lineas);
            $this->recalcularEstado($cuentaId, $id);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Baja logica. Nunca se borra fisico, igual que cliente y producto. */
    public function desactivar(int $cuentaId, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE cotizacion SET activo = 0 WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);

        return $stmt->rowCount() > 0;
    }

    public function activar(int $cuentaId, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE cotizacion SET activo = 1 WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function paramsCabecera(int $cuentaId, ?int $numero, array $datos): array
    {
        return [
            'cuenta' => $cuentaId,
            'numero' => $numero,
            'cliente' => $datos['cliente_id'] ?? null,
            'rut'    => $datos['receptor_rut'],
            'razon'  => $datos['receptor_razon_social'],
            'giro'   => $this->oNull($datos['receptor_giro'] ?? null),
            'dir'    => $this->oNull($datos['receptor_direccion'] ?? null),
            'comuna' => $this->oNull($datos['receptor_comuna'] ?? null),
            'email'  => $this->oNull($datos['receptor_email'] ?? null),
            'fecha'  => $datos['fecha'],
            'valida' => $this->oNull($datos['valida_hasta'] ?? null),
            'notas'  => $this->oNull($datos['notas'] ?? null),
        ];
    }

    /** @param list<array<string,mixed>> $lineas */
    private function insertarLineas(int $cotizacionId, array $lineas): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cotizacion_linea (cotizacion_id, orden, producto_id, nombre, descripcion, '
            . ' unidad, cantidad, cantidad_facturada, precio_unitario, descuento_pct, exento) '
            . 'VALUES (:cot, :orden, :prod, :nombre, :desc, :unidad, :cant, 0, :precio, :dpct, :exento)'
        );
        $orden = 0;
        foreach ($lineas as $l) {
            $orden++;
            $stmt->execute([
                'cot'    => $cotizacionId,
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

    /** '' y null son lo mismo en las columnas opcionales; se guarda NULL. */
    private function oNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
