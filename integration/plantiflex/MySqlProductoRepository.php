<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Maestro de productos/servicios (tabla producto) con aislamiento FORZADO por
 * cuenta_id.
 *
 * PERTENECE AL SISTEMA ANFITRION (panel SaaS), no al paquete agnostico del
 * motor. Trabaja con arrays asociativos (mismo patron que
 * MySqlDteEmitidoRepository), no con DTOs.
 *
 * REGLA DE AISLAMIENTO: cuenta_id es el PRIMER parametro de todo metodo que
 * toque datos y SIEMPRE va en el WHERE. buscarPorId/actualizar/activar/
 * desactivar devuelven null/false de forma uniforme cuando el registro no
 * existe O no pertenece a esa cuenta.
 *
 * El codigo (SKU) es opcional; UNIQUE(cuenta_id, codigo) solo aplica cuando no
 * es NULL. buscarPorCodigo se usa para validar duplicado de SKU antes de crear.
 */
final class MySqlProductoRepository
{
    private const COLUMNAS = 'id, codigo, nombre, descripcion, precio_unitario, unidad, '
        . 'exento, activo, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function filtro(int $cuentaId, ?string $busqueda, bool $soloActivos): array
    {
        $where  = 'cuenta_id = :cuenta_id';
        $params = [':cuenta_id' => $cuentaId];
        if ($soloActivos) {
            $where .= ' AND activo = 1';
        }
        if ($busqueda !== null && trim($busqueda) !== '') {
            $where .= ' AND (codigo LIKE :q OR nombre LIKE :q)';
            $params[':q'] = '%' . trim($busqueda) . '%';
        }
        return [$where, $params];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listar(int $cuentaId, ?string $busqueda = null, bool $soloActivos = true, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM producto WHERE ' . $where
            . ' ORDER BY nombre ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapear'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function contar(int $cuentaId, ?string $busqueda = null, bool $soloActivos = true): int
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM producto WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null null si no existe o no pertenece a la cuenta.
     */
    public function buscarPorId(int $cuentaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM producto WHERE id = :id AND cuenta_id = :cuenta_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':cuenta_id' => $cuentaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapear($row);
    }

    /**
     * Busca por codigo (SKU) NO nulo dentro de la cuenta. Para productos sin
     * codigo no aplica (siempre devuelve null si se pasa cadena vacia).
     *
     * @return array<string,mixed>|null
     */
    public function buscarPorCodigo(int $cuentaId, string $codigo): ?array
    {
        if (trim($codigo) === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM producto WHERE cuenta_id = :cuenta_id AND codigo = :codigo LIMIT 1'
        );
        $stmt->execute([':cuenta_id' => $cuentaId, ':codigo' => trim($codigo)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapear($row);
    }

    /**
     * Inserta un producto y devuelve su id. $datos: nombre obligatorio; codigo/
     * descripcion/precio_unitario/unidad/exento opcionales (exento default 0).
     *
     * @param array<string,mixed> $datos
     *
     * @throws ProductoDuplicadoException si el codigo ya existe en esa cuenta (UNIQUE).
     */
    public function crear(int $cuentaId, array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO producto (cuenta_id, codigo, nombre, descripcion, precio_unitario, unidad, exento) '
            . 'VALUES (:cuenta_id, :codigo, :nombre, :desc, :precio, :unidad, :exento)'
        );
        try {
            $stmt->execute($this->paramsEscritura($cuentaId, $datos));
        } catch (PDOException $e) {
            throw $this->traducirDuplicado($e);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $datos
     *
     * @throws ProductoDuplicadoException si el nuevo codigo choca con otro de la cuenta.
     */
    public function actualizar(int $cuentaId, int $id, array $datos): bool
    {
        if ($this->buscarPorId($cuentaId, $id) === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE producto SET codigo = :codigo, nombre = :nombre, descripcion = :desc, '
            . 'precio_unitario = :precio, unidad = :unidad, exento = :exento '
            . 'WHERE id = :id AND cuenta_id = :cuenta_id'
        );
        $params       = $this->paramsEscritura($cuentaId, $datos);
        $params[':id'] = $id;
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            throw $this->traducirDuplicado($e);
        }

        return true;
    }

    public function desactivar(int $cuentaId, int $id): bool
    {
        return $this->cambiarActivo($cuentaId, $id, 0);
    }

    public function activar(int $cuentaId, int $id): bool
    {
        return $this->cambiarActivo($cuentaId, $id, 1);
    }

    private function cambiarActivo(int $cuentaId, int $id, int $activo): bool
    {
        if ($this->buscarPorId($cuentaId, $id) === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE producto SET activo = :activo WHERE id = :id AND cuenta_id = :cuenta_id'
        );
        $stmt->execute([':activo' => $activo, ':id' => $id, ':cuenta_id' => $cuentaId]);

        return true;
    }

    /**
     * Params comunes de INSERT/UPDATE. codigo/descripcion/unidad vacios -> NULL;
     * precio_unitario vacio/no numerico -> NULL; exento -> 0/1.
     *
     * @param array<string,mixed> $datos
     *
     * @return array<string,mixed>
     */
    private function paramsEscritura(int $cuentaId, array $datos): array
    {
        $precioRaw = $datos['precio_unitario'] ?? null;
        $precio    = ($precioRaw === null || $precioRaw === '' || ! is_numeric($precioRaw))
            ? null
            : (float) $precioRaw;

        return [
            ':cuenta_id' => $cuentaId,
            ':codigo'    => $this->nullSiVacio($datos['codigo'] ?? null),
            ':nombre'    => (string) ($datos['nombre'] ?? ''),
            ':desc'      => $this->nullSiVacio($datos['descripcion'] ?? null),
            ':precio'    => $precio,
            ':unidad'    => $this->nullSiVacio($datos['unidad'] ?? null),
            ':exento'    => ! empty($datos['exento']) ? 1 : 0,
        ];
    }

    private function nullSiVacio(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /**
     * Traduce SOLO el duplicado del UNIQUE (errno 1062). La violacion de FK
     * (errno 1452) tambien es SQLSTATE 23000, por eso se distingue por el errno
     * del driver y se re-propaga como PDOException real.
     */
    private function traducirDuplicado(PDOException $e): RuntimeException
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new ProductoDuplicadoException('Ya existe un producto con ese codigo en esta cuenta.', 0, $e);
        }
        return $e;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function mapear(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'codigo'          => $row['codigo'] !== null ? (string) $row['codigo'] : null,
            'nombre'          => (string) $row['nombre'],
            'descripcion'     => $row['descripcion'] !== null ? (string) $row['descripcion'] : null,
            'precio_unitario' => $row['precio_unitario'] !== null ? (float) $row['precio_unitario'] : null,
            'unidad'          => $row['unidad'] !== null ? (string) $row['unidad'] : null,
            'exento'          => (bool) $row['exento'],
            'activo'          => (bool) $row['activo'],
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }
}
