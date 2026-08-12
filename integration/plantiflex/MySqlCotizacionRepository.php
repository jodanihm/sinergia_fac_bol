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
     * -----------------------------------------------------------------------
     * SI YA HAY UNA TRANSACCION ABIERTA, SE UNE A ELLA EN VEZ DE ABRIR OTRA.
     *
     * PDO no anida transacciones: un beginTransaction() con una ya abierta lanza
     * "There is already an active transaction". Sin esta distincion, un llamador
     * que necesite crear OTRA COSA junto con la cotizacion -- el caso concreto es
     * el chat, que da de alta el cliente y crea la cotizacion en el mismo acto --
     * tendria que elegir entre duplicar el INSERT de este repositorio o dejar un
     * cliente huerfano si la cotizacion falla. El maestro no tiene borrado
     * fisico, asi que ese huerfano seria para siempre.
     *
     * PARA LOS LLAMADORES QUE YA EXISTIAN NO CAMBIA NADA: sin transaccion previa,
     * $propia es true y el flujo es identico al de antes -- begin, commit, y
     * rollback ante cualquier Throwable.
     *
     * CUANDO LA TRANSACCION ES AJENA, ESTE METODO NO HACE ROLLBACK: deshacer lo
     * que otro empezo le borraria trabajo que no es suyo. La excepcion se
     * relanza igual y el dueño de la transaccion decide. Es la regla de siempre:
     * quien abre, cierra.
     *
     * asignarNumero() sigue funcionando en los dos casos -- exige una transaccion
     * abierta y la hay, sea de quien sea.
     * -----------------------------------------------------------------------
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

        $propia = ! $this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
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

            if ($propia) {
                $this->pdo->commit();
            }

            return [$id, $numero];
        } catch (Throwable $e) {
            // Solo se deshace lo que se empezo aqui. Con transaccion ajena, la
            // excepcion sube y el dueño decide -- ver el docblock.
            if ($propia) {
                $this->pdo->rollBack();
            }
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

    // =======================================================================
    //  LA CONVERSION
    // =======================================================================

    /**
     * Valida los id de linea que vinieron del formulario contra la cotizacion Y
     * contra la cuenta, y devuelve el pendiente de cada uno.
     *
     * UN ID QUE VIENE DEL FORMULARIO ES UN ID QUE EL USUARIO ELIGIO. Un hidden
     * es texto que se edita: nada impide mandar el id de una linea de otra
     * cotizacion, o de otra cuenta. Por eso el WHERE lleva las dos condiciones
     * -- c.cuenta_id y l.cotizacion_id -- y el llamador compara lo que pidio con
     * lo que esto devuelve: un id que no vuelve es un id que no existe PARA ESTE
     * USUARIO, y no se distingue "no existe" de "es de otro" a proposito, mismo
     * criterio que MySqlClienteRepository.
     *
     * @param list<int> $lineaIds
     * @return array<int,array{cantidad:float, facturada:float, pendiente:float, unidad:?string, nombre:string}>
     *         indexado por id de linea; solo los que SON de esta cotizacion y cuenta
     */
    public function pendientesDeLineas(int $cuentaId, int $cotizacionId, array $lineaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $lineaIds)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.nombre, l.unidad, l.cantidad, l.cantidad_facturada '
            . 'FROM cotizacion_linea l '
            . 'INNER JOIN cotizacion c ON c.id = l.cotizacion_id '
            . "WHERE c.cuenta_id = ? AND l.cotizacion_id = ? AND l.id IN ({$marcas})"
        );
        $stmt->execute(array_merge([$cuentaId, $cotizacionId], $ids));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[(int) $f['id']] = [
                'nombre'    => (string) $f['nombre'],
                'unidad'    => $f['unidad'],
                'cantidad'  => (float) $f['cantidad'],
                'facturada' => (float) $f['cantidad_facturada'],
                'pendiente' => (float) $f['cantidad'] - (float) $f['cantidad_facturada'],
            ];
        }

        return $out;
    }

    /**
     * Descuenta el saldo y deja el vinculo, EN UNA SOLA TRANSACCION LOCAL.
     *
     * =====================================================================
     * ESTA TRANSACCION NO ENVUELVE LA EMISION, Y ESO ES LO QUE LA DEFINE
     * =====================================================================
     *
     * Cuando esto empieza, LA FACTURA YA EXISTE: el motor devolvio 201, el SII
     * tiene el documento y el folio esta quemado. No hay nada que deshacer.
     *
     * Es lo CONTRARIO del patron del encolado de correos (panel/public/index.php,
     * handleEmisionPost): alli se envuelve lo accesorio -- un correo -- para que
     * no rompa lo esencial. Aqui lo esencial YA PASO y lo que queda es dejar
     * constancia. Por eso el rollback de este metodo NO desemite nada: solo evita
     * dejar el saldo descontado sin vinculo, o el vinculo sin descuento.
     *
     * LOS DOS O NINGUNO. Un saldo descontado sin fila en cotizacion_factura es un
     * descuento que nadie puede rastrear; un vinculo sin descuento deja la
     * cotizacion facturable dos veces.
     *
     * NO SE CAPTURA NADA AQUI. Si esto falla, el llamador tiene que enterarse
     * para poder registrarlo ruidoso Y AVISARLE AL USUARIO QUE LA FACTURA SI SE
     * EMITIO, con su folio -- lo peor que puede pasar es que crea que no y
     * vuelva a emitir, porque los folios no se liberan.
     *
     * @param array<int,float> $cantidadPorLinea id de cotizacion_linea => cantidad a descontar
     * @return int id de la fila de cotizacion_factura
     */
    public function registrarFacturacion(
        int $cuentaId,
        int $cotizacionId,
        string $rutEmisor,
        int $tipoDte,
        int $folio,
        ?string $trackId,
        string $claveIdempotencia,
        array $cantidadPorLinea,
    ): int {
        $this->pdo->beginTransaction();
        try {
            // BLOQUEO DE LAS LINEAS ANTES DE LEER EL SALDO. Sin esto, dos
            // conversiones simultaneas de la misma cotizacion leerian el mismo
            // pendiente y las dos pasarian la comprobacion.
            $lock = $this->pdo->prepare(
                'SELECT l.id FROM cotizacion_linea l '
                . 'INNER JOIN cotizacion c ON c.id = l.cotizacion_id '
                . 'WHERE c.cuenta_id = ? AND l.cotizacion_id = ? FOR UPDATE'
            );
            $lock->execute([$cuentaId, $cotizacionId]);

            $pendientes = $this->pendientesDeLineas($cuentaId, $cotizacionId, array_keys($cantidadPorLinea));

            $factura = $this->pdo->prepare(
                'INSERT INTO cotizacion_factura (cuenta_id, cotizacion_id, rut_emisor, tipo_dte, '
                . ' folio, track_id, clave_idempotencia) '
                . 'VALUES (:cuenta, :cot, :rut, :tipo, :folio, :track, :clave)'
            );
            $factura->execute([
                'cuenta' => $cuentaId,
                'cot'    => $cotizacionId,
                'rut'    => $rutEmisor,
                'tipo'   => $tipoDte,
                'folio'  => $folio,
                'track'  => $trackId,
                'clave'  => $claveIdempotencia,
            ]);
            $facturaId = (int) $this->pdo->lastInsertId();

            $insLinea = $this->pdo->prepare(
                'INSERT INTO cotizacion_factura_linea (cotizacion_factura_id, cotizacion_linea_id, cantidad) '
                . 'VALUES (?, ?, ?)'
            );
            // EL UPDATE LLEVA SU PROPIA GUARDA EN EL WHERE, ademas del CHECK de
            // la migracion 032 y de la comprobacion en PHP. Tres capas, y la que
            // manda es la de la base: si otra transaccion se colo entre la
            // lectura y esto, rowCount() sale 0 y revienta.
            $updSaldo = $this->pdo->prepare(
                'UPDATE cotizacion_linea SET cantidad_facturada = cantidad_facturada + :cant '
                . 'WHERE id = :id AND cantidad_facturada + :cant2 <= cantidad'
            );

            foreach ($cantidadPorLinea as $lineaId => $cantidad) {
                $lineaId  = (int) $lineaId;
                $cantidad = (float) $cantidad;

                if ($cantidad <= 0) {
                    continue; // una linea con 0 no descuenta ni deja rastro
                }
                if (! isset($pendientes[$lineaId])) {
                    throw new RuntimeException(
                        "la linea {$lineaId} no pertenece a la cotizacion {$cotizacionId} de esta cuenta."
                    );
                }
                if ($cantidad > $pendientes[$lineaId]['pendiente'] + 0.00005) {
                    throw new RuntimeException(sprintf(
                        'la linea %d tiene %s pendiente y se intento facturar %s.',
                        $lineaId,
                        rtrim(rtrim(number_format($pendientes[$lineaId]['pendiente'], 4, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($cantidad, 4, '.', ''), '0'), '.'),
                    ));
                }

                $insLinea->execute([$facturaId, $lineaId, $cantidad]);
                $updSaldo->execute(['cant' => $cantidad, 'id' => $lineaId, 'cant2' => $cantidad]);
                if ($updSaldo->rowCount() !== 1) {
                    throw new RuntimeException(
                        "el saldo de la linea {$lineaId} cambio mientras se facturaba; no se descontó."
                    );
                }
            }

            $this->recalcularEstado($cuentaId, $cotizacionId);
            $this->pdo->commit();

            return $facturaId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Facturas que salieron de una cotizacion, con lo que consumio cada una.
     *
     * @return list<array<string,mixed>>
     */
    public function facturasDe(int $cuentaId, int $cotizacionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.tipo_dte, f.folio, f.track_id, f.created_at, '
            . '       COALESCE(SUM(fl.cantidad), 0) AS cantidad_total '
            . 'FROM cotizacion_factura f '
            . 'LEFT JOIN cotizacion_factura_linea fl ON fl.cotizacion_factura_id = f.id '
            . 'WHERE f.cuenta_id = ? AND f.cotizacion_id = ? '
            . 'GROUP BY f.id, f.tipo_dte, f.folio, f.track_id, f.created_at '
            . 'ORDER BY f.id ASC'
        );
        $stmt->execute([$cuentaId, $cotizacionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * De que cotizacion salio una factura. EL VINCULO INVERSO.
     *
     * Se consulta desde el lado del panel y no desde dte_emitido, que es tabla
     * del motor y no puede llevar esta referencia.
     *
     * @return array{cotizacion_id:int, numero:int}|null
     */
    public function cotizacionDeFactura(int $cuentaId, string $rutEmisor, int $tipoDte, int $folio): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id AS cotizacion_id, c.numero FROM cotizacion_factura f '
            . 'INNER JOIN cotizacion c ON c.id = f.cotizacion_id '
            . 'WHERE f.cuenta_id = ? AND f.rut_emisor = ? AND f.tipo_dte = ? AND f.folio = ? LIMIT 1'
        );
        $stmt->execute([$cuentaId, $rutEmisor, $tipoDte, $folio]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : ['cotizacion_id' => (int) $r['cotizacion_id'], 'numero' => (int) $r['numero']];
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
