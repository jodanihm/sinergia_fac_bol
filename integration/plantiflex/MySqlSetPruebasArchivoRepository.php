<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Persiste el archivo SIISetDePruebas<RUT>.txt subido por el tenant (tabla
 * dte_set_pruebas_archivo). Un solo archivo vigente por rut_emisor+ambiente:
 * guardar() reemplaza el anterior (no hay historial).
 *
 * El contenido se guarda en CLARO, igual que dte_libro/dte_emitido: es un
 * archivo de pruebas de certificacion del propio SII, no material secreto (a
 * diferencia del CAF/certificado).
 */
final class MySqlSetPruebasArchivoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function guardar(string $rutEmisor, Ambiente $ambiente, string $nombreArchivo, string $contenido): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_set_pruebas_archivo (rut_emisor, ambiente, nombre_archivo, contenido) '
            . 'VALUES (:rut, :amb, :nombre, :contenido) '
            . 'ON DUPLICATE KEY UPDATE nombre_archivo = VALUES(nombre_archivo), '
            . 'contenido = VALUES(contenido), created_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':rut', $rutEmisor);
        $stmt->bindValue(':amb', $ambiente->value);
        $stmt->bindValue(':nombre', $nombreArchivo);
        $stmt->bindValue(':contenido', $contenido, PDO::PARAM_LOB);
        $stmt->execute();
    }

    /** @return array{nombre_archivo: string, contenido: string, created_at: string}|null */
    public function obtener(string $rutEmisor, Ambiente $ambiente): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT nombre_archivo, contenido, created_at FROM dte_set_pruebas_archivo '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }
}
