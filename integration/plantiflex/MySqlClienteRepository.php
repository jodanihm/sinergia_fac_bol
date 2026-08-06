<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Maestro de clientes (tabla cliente) con aislamiento FORZADO por cuenta_id.
 *
 * PERTENECE AL SISTEMA ANFITRION (panel SaaS), no al paquete agnostico del
 * motor: por eso vive en integration/plantiflex/ y trabaja con arrays
 * asociativos (mismo patron que MySqlDteEmitidoRepository), no con DTOs del
 * contrato del motor.
 *
 * REGLA DE AISLAMIENTO: cuenta_id es el PRIMER parametro de todo metodo que
 * toque datos y SIEMPRE va en el WHERE. buscarPorId/actualizar/activar/
 * desactivar devuelven null/false de forma uniforme cuando el registro no
 * existe O no pertenece a esa cuenta -- nunca distinguen ambos casos, para no
 * filtrar la existencia de datos de otro tenant.
 *
 * El rut_cliente se guarda YA NORMALIZADO (responsabilidad del handler, via
 * Rut::normalizar). Los metodos de este repo asumen que reciben el RUT en su
 * forma canonica.
 */
final class MySqlClienteRepository
{
    /** Columnas devueltas al anfitrion (nunca se expone nada mas). */
    private const COLUMNAS = 'id, rut_cliente, razon_social, giro, direccion, comuna, '
        . 'email, telefono, activo, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Condicion SQL de "a este cliente le falta algo para poder facturarlo".
     *
     * Vive aqui y no en el panel para que el FILTRO del listado y la MARCA de
     * cada fila no puedan discrepar: los dos salen de esta definicion. El panel
     * expone la misma regla en PHP con clienteCamposFaltantes(), que ademas dice
     * QUE campo falta; esta constante es solo la version SQL para poder filtrar
     * sin traer todas las filas.
     *
     * Cubre NULL y cadena vacia: el ABM guarda '' cuando el input viene en
     * blanco (validarCliente() hace trim y no convierte a NULL), mientras que
     * las filas creadas por otros caminos pueden traer NULL. Las dos cosas
     * significan lo mismo aqui.
     *
     * Por que estos tres campos: sin ellos el SII no acepta la factura. Ver
     * CLIENTE_CAMPOS_PARA_FACTURAR en el panel para la cita del Formato DTE.
     */
    private const SQL_INCOMPLETO =
        "(COALESCE(giro, '') = '' OR COALESCE(direccion, '') = '' OR COALESCE(comuna, '') = '')";

    /**
     * WHERE + params comunes del filtro de listado (scope por cuenta + busqueda
     * opcional por rut/razon social + solo activos opcional + solo incompletos
     * opcional).
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function filtro(int $cuentaId, ?string $busqueda, bool $soloActivos, bool $soloIncompletos = false): array
    {
        $where  = 'cuenta_id = :cuenta_id';
        $params = [':cuenta_id' => $cuentaId];
        if ($soloActivos) {
            $where .= ' AND activo = 1';
        }
        if ($busqueda !== null && trim($busqueda) !== '') {
            $where .= ' AND (rut_cliente LIKE :q OR razon_social LIKE :q)';
            $params[':q'] = '%' . trim($busqueda) . '%';
        }
        if ($soloIncompletos) {
            $where .= ' AND ' . self::SQL_INCOMPLETO;
        }
        return [$where, $params];
    }

    /**
     * Cuantos clientes de la cuenta no se pueden facturar por datos faltantes.
     *
     * Se cuenta SIEMPRE sobre los ACTIVOS: un cliente dado de baja no se va a
     * facturar de todos modos, y contarlo inflaria el aviso con ruido que nadie
     * va a ir a arreglar.
     */
    public function contarIncompletos(int $cuentaId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cliente WHERE cuenta_id = :cuenta_id AND activo = 1 AND ' . self::SQL_INCOMPLETO
        );
        $stmt->execute([':cuenta_id' => $cuentaId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listar(int $cuentaId, ?string $busqueda = null, bool $soloActivos = true, int $limit = 50, int $offset = 0, bool $soloIncompletos = false): array
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos, $soloIncompletos);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM cliente WHERE ' . $where
            . ' ORDER BY razon_social ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapear'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function contar(int $cuentaId, ?string $busqueda = null, bool $soloActivos = true, bool $soloIncompletos = false): int
    {
        [$where, $params] = $this->filtro($cuentaId, $busqueda, $soloActivos, $soloIncompletos);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cliente WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null null si no existe o no pertenece a la cuenta.
     */
    public function buscarPorId(int $cuentaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM cliente WHERE id = :id AND cuenta_id = :cuenta_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':cuenta_id' => $cuentaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapear($row);
    }

    /**
     * Espera el RUT YA normalizado (Rut::normalizar).
     *
     * @return array<string,mixed>|null
     */
    public function buscarPorRut(int $cuentaId, string $rutNormalizado): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM cliente WHERE cuenta_id = :cuenta_id AND rut_cliente = :rut LIMIT 1'
        );
        $stmt->execute([':cuenta_id' => $cuentaId, ':rut' => $rutNormalizado]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapear($row);
    }

    /**
     * Version en lote de buscarPorRut(): UNA sola query para resolver varios
     * RUT de una vez (evita N+1 al pintar un listado, ej. el de M5). Espera los
     * RUT YA normalizados (Rut::normalizar).
     *
     * @param list<string> $rutsNormalizados
     *
     * @return array<string,array<string,mixed>> mapa rut_cliente => cliente
     */
    public function buscarPorRuts(int $cuentaId, array $rutsNormalizados): array
    {
        $ruts = array_values(array_unique($rutsNormalizados));
        if ($ruts === []) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ruts), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM cliente '
            . "WHERE cuenta_id = ? AND rut_cliente IN ({$marcadores})"
        );
        $stmt->execute([$cuentaId, ...$ruts]);

        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cliente = $this->mapear($row);
            $mapa[$cliente['rut_cliente']] = $cliente;
        }

        return $mapa;
    }

    /**
     * Inserta un cliente y devuelve su id. $datos: rut_cliente (normalizado) y
     * razon_social obligatorios; giro/direccion/comuna/email/telefono opcionales.
     *
     * @param array<string,mixed> $datos
     *
     * @throws ClienteDuplicadoException si el RUT ya existe en esa cuenta (UNIQUE).
     */
    public function crear(int $cuentaId, array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cliente (cuenta_id, rut_cliente, razon_social, giro, direccion, comuna, email, telefono) '
            . 'VALUES (:cuenta_id, :rut, :razon, :giro, :dir, :comuna, :email, :tel)'
        );
        try {
            $stmt->execute($this->paramsEscritura($cuentaId, $datos));
        } catch (PDOException $e) {
            throw $this->traducirDuplicado($e);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Rellena el email de un cliente SOLO si hoy esta vacio o nulo. Devuelve
     * true si escribio, false si no habia nada que hacer.
     *
     * POR QUE UN METODO PROPIO Y NO actualizar(): aquel reescribe las siete
     * columnas editables de una, asi que usarlo para rellenar un solo campo
     * obligaria a releer y reenviar el resto, y cualquier dato desactualizado en
     * esa lectura pisaria al bueno. Aqui se toca una columna y nada mas.
     *
     * LA GUARDA DE NO-SOBRESCRITURA VIVE EN EL WHERE, no en un if de PHP. Un
     * "leer, comprobar vacio, escribir" en dos viajes deja una ventana entre la
     * lectura y la escritura: si otra request carga un correo en ese hueco, el
     * UPDATE lo pisaria igual. Con la condicion dentro del propio UPDATE, el
     * motor evalua y escribe en la misma operacion y un correo ya existente es
     * intocable pase lo que pase. Es la parte innegociable de este cambio.
     *
     * Nota sobre rowCount(): con un valor identico al que ya estaba, MySQL
     * informa 0 filas afectadas aunque la condicion calce. No importa aqui,
     * porque el WHERE exige que estuviera vacio o nulo: si calzo, el valor
     * nuevo es necesariamente distinto del anterior.
     */
    public function rellenarEmailSiVacio(int $cuentaId, int $id, string $email): bool
    {
        if ($email === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cliente SET email = :email '
            . "WHERE id = :id AND cuenta_id = :cuenta_id AND (email IS NULL OR email = '')"
        );
        $stmt->execute([':email' => $email, ':id' => $id, ':cuenta_id' => $cuentaId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Actualiza los datos editables de un cliente de la cuenta. Devuelve false
     * si el id no existe o no pertenece a la cuenta (nunca lanza "no encontrado"
     * distinguible). 'activo' NO se toca aqui (ver activar/desactivar).
     *
     * @param array<string,mixed> $datos
     *
     * @throws ClienteDuplicadoException si el nuevo RUT choca con otro de la cuenta.
     */
    public function actualizar(int $cuentaId, int $id, array $datos): bool
    {
        if ($this->buscarPorId($cuentaId, $id) === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE cliente SET rut_cliente = :rut, razon_social = :razon, giro = :giro, '
            . 'direccion = :dir, comuna = :comuna, email = :email, telefono = :tel '
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
            'UPDATE cliente SET activo = :activo WHERE id = :id AND cuenta_id = :cuenta_id'
        );
        $stmt->execute([':activo' => $activo, ':id' => $id, ':cuenta_id' => $cuentaId]);

        return true;
    }

    /**
     * Params comunes de INSERT/UPDATE (todos los campos editables). Los
     * opcionales ausentes quedan en NULL.
     *
     * @param array<string,mixed> $datos
     *
     * @return array<string,mixed>
     */
    private function paramsEscritura(int $cuentaId, array $datos): array
    {
        return [
            ':cuenta_id' => $cuentaId,
            ':rut'       => (string) ($datos['rut_cliente'] ?? ''),
            ':razon'     => (string) ($datos['razon_social'] ?? ''),
            ':giro'      => $this->nullSiVacio($datos['giro'] ?? null),
            ':dir'       => $this->nullSiVacio($datos['direccion'] ?? null),
            ':comuna'    => $this->nullSiVacio($datos['comuna'] ?? null),
            ':email'     => $this->nullSiVacio($datos['email'] ?? null),
            ':tel'       => $this->nullSiVacio($datos['telefono'] ?? null),
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
     * Traduce SOLO el duplicado del UNIQUE (errno 1062) a la excepcion de
     * dominio. La violacion de FK (errno 1452) tambien es SQLSTATE 23000, por
     * eso se distingue por el errno del driver y NO se captura aqui: se
     * re-propaga como PDOException real.
     */
    private function traducirDuplicado(PDOException $e): RuntimeException
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new ClienteDuplicadoException('Ya existe un cliente con ese RUT en esta cuenta.', 0, $e);
        }
        return $e;
    }

    /**
     * Castea la fila cruda de PDO a tipos PHP.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function mapear(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'rut_cliente'  => (string) $row['rut_cliente'],
            'razon_social' => (string) $row['razon_social'],
            'giro'         => $row['giro'] !== null ? (string) $row['giro'] : null,
            'direccion'    => $row['direccion'] !== null ? (string) $row['direccion'] : null,
            'comuna'       => $row['comuna'] !== null ? (string) $row['comuna'] : null,
            'email'        => $row['email'] !== null ? (string) $row['email'] : null,
            'telefono'     => $row['telefono'] !== null ? (string) $row['telefono'] : null,
            'activo'       => (bool) $row['activo'],
            'created_at'   => (string) $row['created_at'],
            'updated_at'   => (string) $row['updated_at'],
        ];
    }
}
