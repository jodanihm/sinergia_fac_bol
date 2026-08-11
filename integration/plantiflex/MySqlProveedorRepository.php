<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Maestro de proveedores (tabla proveedor, migracion 036) con aislamiento
 * FORZADO por cuenta_id.
 *
 * ESPEJO DE MySqlClienteRepository, MENOS LA CAPA DE "PUEDE FACTURARLE".
 * Aquel tiene SQL_INCOMPLETO, contarIncompletos() y el filtro de incompletos,
 * todo justificado por una regla del SII: "sin giro, direccion y comuna el SII no
 * acepta la factura". A UN PROVEEDOR NO SE LE EMITE NINGUN DTE, asi que esa
 * regla no existe aqui y no se copia. Si algun dia hay una definicion de
 * "proveedor incompleto", la dara el negocio y sera otra cosa.
 *
 * REGLA DE AISLAMIENTO, identica a la de cliente: cuenta_id es el PRIMER
 * parametro de todo metodo que toque datos y SIEMPRE va en el WHERE.
 * buscarPorId/actualizar/activar/desactivar devuelven null o false de forma
 * uniforme cuando el registro no existe O es de otra cuenta -- nunca distinguen
 * los dos casos, para no filtrar la existencia de datos de otro tenant.
 *
 * El rut_proveedor se guarda YA NORMALIZADO (responsabilidad del handler, via
 * Rut::normalizar), igual que en cliente.
 */
final class MySqlProveedorRepository
{
    private const COLUMNAS = 'id, rut_proveedor, razon_social, giro, direccion, comuna, '
        . 'email, telefono, contacto, condiciones_pago, activo, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{0:string, 1:list<mixed>} WHERE + params del listado. */
    private function filtro(int $cuentaId, ?string $busqueda, bool $soloActivos): array
    {
        $where  = 'WHERE cuenta_id = ?';
        $params = [$cuentaId];

        if ($soloActivos) {
            $where .= ' AND activo = 1';
        }
        if ($busqueda !== null && trim($busqueda) !== '') {
            $like = '%' . trim($busqueda) . '%';
            $where .= ' AND (razon_social LIKE ? OR rut_proveedor LIKE ? OR contacto LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    /** @return list<array<string,mixed>> */
    public function listar(
        int $cuentaId,
        ?string $busqueda = null,
        bool $soloActivos = true,
        int $limit = 50,
        int $offset = 0,
    ): array {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM proveedor ' . $where
            . ' ORDER BY razon_social ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(int $cuentaId, ?string $busqueda = null, bool $soloActivos = true): int
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM proveedor ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function buscarPorId(int $cuentaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNAS . ' FROM proveedor WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /** @param string $rutNormalizado ya pasado por Rut::normalizar(). */
    public function buscarPorRut(int $cuentaId, string $rutNormalizado): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM proveedor WHERE cuenta_id = ? AND rut_proveedor = ?'
        );
        $stmt->execute([$cuentaId, $rutNormalizado]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /**
     * @param array<string,mixed> $datos rut_proveedor y razon_social obligatorios.
     * @throws ProveedorDuplicadoException si el RUT ya existe en esa cuenta.
     */
    public function crear(int $cuentaId, array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO proveedor (cuenta_id, rut_proveedor, razon_social, giro, direccion, comuna, '
            . ' email, telefono, contacto, condiciones_pago) '
            . 'VALUES (:cuenta, :rut, :razon, :giro, :dir, :comuna, :email, :tel, :contacto, :cond)'
        );
        try {
            $stmt->execute($this->params($cuentaId, $datos));
        } catch (PDOException $e) {
            throw $this->traducirDuplicado($e, (string) $datos['rut_proveedor']);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $datos */
    public function actualizar(int $cuentaId, int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE proveedor SET rut_proveedor = :rut, razon_social = :razon, giro = :giro, '
            . ' direccion = :dir, comuna = :comuna, email = :email, telefono = :tel, '
            . ' contacto = :contacto, condiciones_pago = :cond '
            . 'WHERE cuenta_id = :cuenta AND id = :id'
        );
        $params = $this->params($cuentaId, $datos);
        $params['id'] = $id;
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            throw $this->traducirDuplicado($e, (string) $datos['rut_proveedor']);
        }

        return $stmt->rowCount() > 0;
    }

    public function desactivar(int $cuentaId, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE proveedor SET activo = 0 WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);

        return $stmt->rowCount() > 0;
    }

    public function activar(int $cuentaId, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE proveedor SET activo = 1 WHERE cuenta_id = ? AND id = ?');
        $stmt->execute([$cuentaId, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Traduce SOLO el duplicado del UNIQUE (errno 1062) a la excepcion de
     * dominio. Copia el patron de MySqlClienteRepository::traducirDuplicado().
     *
     * EL TIPO DE RETORNO ES RuntimeException, Y NO PDOException. Este metodo
     * devolvia PDOException y ahi estaba el defecto: ProveedorDuplicadoException
     * extiende RuntimeException, NO PDOException, asi que devolverla desde una
     * firma que promete PDOException lanzaba un TypeError -- y el llamador veia
     * un error generico en vez del duplicado. RuntimeException es el tipo que
     * cubre las dos ramas, porque PDOException tambien lo extiende. Es
     * exactamente el tipo que declara cliente.
     *
     * SE DISTINGUE POR EL errno DEL DRIVER Y NO POR EL SQLSTATE: la violacion de
     * FK (errno 1452) tambien es SQLSTATE 23000, y convertirla en "RUT repetido"
     * escondería un problema distinto detras de un mensaje tranquilizador.
     * Cualquier otro error de PDO se re-propaga tal cual.
     *
     * EL MENSAJE NOMBRA EL RUT, a diferencia del de cliente: quien ve el error
     * suele estar cargando varios proveedores seguidos, y "ese RUT" no le dice
     * cual de los que tecleo ya existia.
     */
    private function traducirDuplicado(PDOException $e, string $rut): RuntimeException
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new ProveedorDuplicadoException(
                "Ya existe un proveedor con el RUT {$rut} en esta cuenta.", 0, $e
            );
        }

        return $e;
    }

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function params(int $cuentaId, array $datos): array
    {
        return [
            'cuenta'   => $cuentaId,
            'rut'      => $datos['rut_proveedor'],
            'razon'    => $datos['razon_social'],
            'giro'     => $this->oNull($datos['giro'] ?? null),
            'dir'      => $this->oNull($datos['direccion'] ?? null),
            'comuna'   => $this->oNull($datos['comuna'] ?? null),
            'email'    => $this->oNull($datos['email'] ?? null),
            'tel'      => $this->oNull($datos['telefono'] ?? null),
            'contacto' => $this->oNull($datos['contacto'] ?? null),
            'cond'     => $this->oNull($datos['condiciones_pago'] ?? null),
        ];
    }

    /** '' y null son lo mismo en las columnas opcionales; se guarda NULL. */
    private function oNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
