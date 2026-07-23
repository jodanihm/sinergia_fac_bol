<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use Closure;
use PDO;
use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;
use Throwable;

/**
 * Implementacion del FolioRepositoryInterface usando MySQL / PDO.
 *
 * PERTENECE AL SISTEMA ANFITRION (Plantiflex), no al paquete facturacion-cl.
 * Vive en integration/plantiflex/ porque depende de la base de datos del
 * sistema. El paquete sigue siendo agnostico.
 *
 * Reglas que esta implementacion respeta (ver tambien doc del contrato):
 *
 *   1. Numeracion correlativa SIN saltos ni repeticiones. Se logra con
 *      transaccion + SELECT ... FOR UPDATE sobre las filas de dte_folio.
 *   2. Un folio se QUEMA al asignarse. Si el SII luego rechaza, se registra
 *      con emision_exitosa=false; el folio NO se reutiliza desde aqui.
 *   3. NO administra certificado ni llave privada. Solo folios/CAF.
 *   4. El CAF se guarda CIFRADO en la tabla. El sistema anfitrion inyecta
 *      las closures de cifrado/descifrado en el constructor.
 *
 * Requiere que la conexion PDO tenga PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION
 * para que los errores se propaguen y disparen rollback.
 */
final class MySqlFolioRepository implements FolioRepositoryInterface
{
    /**
     * @param PDO                   $pdo       Conexion a la base del anfitrion.
     * @param Closure|null          $descifrar fn(string $cifrado): string. Si null, se asume
     *                                         que la columna guarda el XML en claro (no recomendado).
     *                                         Camino LEGACY: descifra directo con la KEK (dek_envuelta
     *                                         NULL/vacia); se preserva tal cual para compatibilidad.
     * @param Closure|null          $cifrar    fn(string $plano): string. Solo necesario si esta
     *                                         clase carga CAFs nuevos; no se usa en el flujo
     *                                         de asignacion. Se acepta para que el cifrado quede
     *                                         consistente en todo el modulo.
     * @param CertificadoCrypto|null $cryptoKek Instancia con la KEK cruda (misma que usa
     *                                          MySqlEmisorRepository). Necesaria SOLO para
     *                                          envelope encryption: desenvolver dek_envuelta
     *                                          cuando el CAF se cifro con una DEK por-CAF (ver
     *                                          descifrarCaf()). Si es null, el CAF con
     *                                          dek_envuelta no se puede descifrar aqui (cae al
     *                                          camino $descifrar, que fallaria igual que antes
     *                                          de esta migracion si la clave no coincide).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Closure $descifrar = null,
        private readonly ?Closure $cifrar = null,
        private readonly ?CertificadoCrypto $cryptoKek = null,
    ) {
    }

    public function asignarSiguienteFolio(string $rutEmisor, TipoDte $tipo, Ambiente $ambiente): int
    {
        $this->pdo->beginTransaction();
        try {
            // IMPORTANTE: el lock NO filtra por c.estado = 'activo'.
            //
            // Si filtraramos por estado, dos transacciones concurrentes podrian
            // bloquear conjuntos distintos de filas (una ve el CAF como activo,
            // la otra lo ve como agotado tras un UPDATE concurrente) y caer en
            // el borde de agotamiento eligiendo el mismo folio o violando el
            // UNIQUE de dte_folio_log. En cambio bloqueando TODAS las filas de
            // dte_folio del emisor/tipo/ambiente, ambas transacciones se
            // serializan sobre el mismo conjunto y el estado 'agotado' se
            // evalua en PHP de forma consistente.
            $sql = 'SELECT f.id, f.caf_id, f.proximo_folio, f.folio_hasta, c.estado '
                . 'FROM dte_folio f '
                . 'INNER JOIN dte_caf c ON c.id = f.caf_id '
                . 'WHERE f.rut_emisor = :rut '
                . '  AND f.tipo_dte = :tipo '
                . '  AND f.ambiente = :amb '
                . 'ORDER BY c.folio_desde ASC ';
            $lock = $this->lockClause();
            if ($lock !== '') {
                $sql .= $lock;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':rut'  => $rutEmisor,
                ':tipo' => $tipo->value,
                ':amb'  => $ambiente->value,
            ]);
            $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($candidatos as $row) {
                $estado     = (string) $row['estado'];
                $proximo    = (int) $row['proximo_folio'];
                $folioHasta = (int) $row['folio_hasta'];
                $cafId      = (int) $row['caf_id'];
                $folioId    = (int) $row['id'];

                // El estado 'agotado' se respeta como optimizacion/lectura
                // (no como filtro del lock). Si el CAF quedo agotado por una
                // transaccion previa, lo saltamos.
                if ($estado === 'agotado' || $proximo > $folioHasta) {
                    if ($estado !== 'agotado') {
                        $this->marcarCafAgotado($cafId);
                    }
                    continue;
                }

                $folio = $proximo;

                // Incrementar contador.
                $upd = $this->pdo->prepare(
                    'UPDATE dte_folio SET proximo_folio = proximo_folio + 1 WHERE id = :id'
                );
                $upd->execute([':id' => $folioId]);

                // Si con este consumo se acaba el rango, marcar CAF agotado.
                if ($folio === $folioHasta) {
                    $this->marcarCafAgotado($cafId);
                }

                // Bitacora: el UNIQUE KEY de (rut, tipo, ambiente, folio) actua
                // como segundo cinturon de seguridad. Si por algun bug otra
                // transaccion ya registro este folio, la insercion falla y la
                // transaccion entera hace rollback.
                $log = $this->pdo->prepare(
                    'INSERT INTO dte_folio_log '
                    . '(rut_emisor, tipo_dte, ambiente, folio, emision_exitosa, asignado_at) '
                    . 'VALUES (:rut, :tipo, :amb, :folio, NULL, CURRENT_TIMESTAMP)'
                );
                $log->execute([
                    ':rut'   => $rutEmisor,
                    ':tipo'  => $tipo->value,
                    ':amb'   => $ambiente->value,
                    ':folio' => $folio,
                ]);

                $this->pdo->commit();
                return $folio;
            }

            // Ningun CAF tenia folios disponibles. Confirmamos los posibles
            // "marcar agotado" antes de lanzar la excepcion.
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        throw new FoliosAgotadosException(sprintf(
            'No quedan folios para %s tipo %d ambiente %s',
            $rutEmisor,
            $tipo->value,
            $ambiente->value,
        ));
    }

    public function obtenerCafActivo(string $rutEmisor, TipoDte $tipo, Ambiente $ambiente): string
    {
        // Tomamos el CAF activo de menor folio_desde que aun no este agotado.
        $stmt = $this->pdo->prepare(
            'SELECT c.caf_xml_cifrado, c.dek_envuelta '
            . 'FROM dte_caf c '
            . 'INNER JOIN dte_folio f ON f.caf_id = c.id '
            . 'WHERE c.rut_emisor = :rut '
            . '  AND c.tipo_dte = :tipo '
            . '  AND c.ambiente = :amb '
            . "  AND c.estado = 'activo' "
            . '  AND f.proximo_folio <= f.folio_hasta '
            . 'ORDER BY c.folio_desde ASC '
            . 'LIMIT 1'
        );
        $stmt->execute([
            ':rut'  => $rutEmisor,
            ':tipo' => $tipo->value,
            ':amb'  => $ambiente->value,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || ! isset($row['caf_xml_cifrado'])) {
            throw new FoliosAgotadosException(sprintf(
                'No hay CAF activo para %s tipo %d ambiente %s',
                $rutEmisor,
                $tipo->value,
                $ambiente->value,
            ));
        }

        return $this->descifrarCaf((string) $row['caf_xml_cifrado'], (string) ($row['dek_envuelta'] ?? ''));
    }

    public function marcarFolioComoUsado(
        string $rutEmisor,
        TipoDte $tipo,
        Ambiente $ambiente,
        int $folio,
        bool $emisionExitosa,
    ): void {
        $upd = $this->pdo->prepare(
            'UPDATE dte_folio_log '
            . 'SET emision_exitosa = :res '
            . 'WHERE rut_emisor = :rut '
            . '  AND tipo_dte = :tipo '
            . '  AND ambiente = :amb '
            . '  AND folio = :folio '
            . '  AND emision_exitosa IS NULL'
        );
        $upd->execute([
            ':res'   => $emisionExitosa ? 1 : 0,
            ':rut'   => $rutEmisor,
            ':tipo'  => $tipo->value,
            ':amb'   => $ambiente->value,
            ':folio' => $folio,
        ]);
    }

    private function marcarCafAgotado(int $cafId): void
    {
        $this->pdo->prepare("UPDATE dte_caf SET estado = 'agotado' WHERE id = :id")
            ->execute([':id' => $cafId]);
    }

    /**
     * Descifra el XML del CAF: envelope encryption si viene $dekEnvuelta (DEK
     * aleatoria por CAF, envuelta con la KEK), o el camino LEGACY (closure
     * inyectada, que descifra directo con la KEK) si $dekEnvuelta es NULL/vacia.
     *
     * IMPORTANTE: el CAF se cifro sobre sus bytes ORIGINALES (ISO-8859-1, sin
     * convertir) -- esta funcion NUNCA debe aplicar conversion de encoding;
     * devuelve exactamente lo que entrega CertificadoCrypto::descifrar().
     *
     * El metodo se llama descifrarCaf() (no descifrar()) para evitar colisionar
     * con la propiedad $descifrar: en PHP $this->descifrar siempre resuelve a
     * la propiedad, nunca al metodo del mismo nombre.
     */
    private function descifrarCaf(string $cifrado, string $dekEnvuelta = ''): string
    {
        if (trim($dekEnvuelta) !== '' && $this->cryptoKek !== null) {
            $dek = $this->cryptoKek->descifrar($dekEnvuelta);
            return (new CertificadoCrypto($dek))->descifrar($cifrado);
        }

        if ($this->descifrar !== null) {
            return ($this->descifrar)($cifrado);
        }
        // Sin closure -> se asume texto en claro. Util para tests; en produccion
        // el sistema anfitrion DEBE pasar una closure real.
        return $cifrado;
    }

    /**
     * Clausula de lock para SELECT.
     *
     * En MySQL: 'FOR UPDATE' (lock pesimista por fila dentro de la transaccion).
     * En SQLite no existe esta clausula; la concurrencia se valida en staging
     * con MySQL real, no en los tests unitarios contra sqlite::memory:.
     */
    private function lockClause(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'sqlite' ? '' : 'FOR UPDATE';
    }
}
