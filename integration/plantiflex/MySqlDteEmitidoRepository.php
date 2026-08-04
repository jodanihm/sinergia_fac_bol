<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Contracts\DteEmitidoRepositoryInterface;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\EstadoContable;

/**
 * Implementacion MySQL de {@see DteEmitidoRepositoryInterface} (tabla dte_emitido).
 *
 * El XML se guarda en CLARO: el DTE es un documento que se entrega al receptor,
 * no material secreto (a diferencia del CAF/certificado, que si se cifran).
 */
final class MySqlDteEmitidoRepository implements DteEmitidoRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        ?string $trackId,
        string $estado,
        string $xml,
        string $fechaEmision,
        int $neto,
        int $iva,
        int $total,
        string $receptorRut,
        ?int $folioRef,
        ?int $tipoDteRef,
        // Migracion 026. Van con default null y AL FINAL de la firma para que los
        // llamadores que no los pasan sigan compilando: la boleta y cualquier
        // camino que todavia no capture forma de pago.
        ?int $formaPago = null,
        ?string $fechaVencimiento = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_emitido '
            . '(rut_emisor, ambiente, tipo_dte, folio, track_id, estado, xml, fecha_emision, '
            . ' neto, iva, total, receptor_rut, folio_ref, tipo_dte_ref, forma_pago, fecha_vencimiento) '
            . 'VALUES (:rut, :amb, :tipo, :folio, :track, :estado, :xml, :fecha, '
            . ' :neto, :iva, :total, :rrut, :fref, :tref, :fpago, :fvenc) '
            . 'ON DUPLICATE KEY UPDATE '
            . ' track_id = VALUES(track_id), estado = VALUES(estado), xml = VALUES(xml), '
            . ' fecha_emision = VALUES(fecha_emision), neto = VALUES(neto), iva = VALUES(iva), '
            . ' total = VALUES(total), receptor_rut = VALUES(receptor_rut), '
            . ' folio_ref = VALUES(folio_ref), tipo_dte_ref = VALUES(tipo_dte_ref), '
            . ' forma_pago = VALUES(forma_pago), fecha_vencimiento = VALUES(fecha_vencimiento)'
        );
        $stmt->execute([
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente->value,
            ':tipo'   => $tipoDte,
            ':folio'  => $folio,
            ':track'  => $trackId,
            ':estado' => $estado,
            ':xml'    => $xml,
            ':fecha'  => $fechaEmision,
            ':neto'   => $neto,
            ':iva'    => $iva,
            ':total'  => $total,
            ':rrut'   => $receptorRut,
            ':fref'   => $folioRef,
            ':tref'   => $tipoDteRef,
            ':fpago'  => $formaPago,
            ':fvenc'  => $fechaVencimiento,
        ]);
    }

    public function obtenerXml(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
    ): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT xml FROM dte_emitido '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = :tipo AND folio = :folio '
            . 'LIMIT 1'
        );
        $stmt->execute([
            ':rut'   => $rutEmisor,
            ':amb'   => $ambiente->value,
            ':tipo'  => $tipoDte,
            ':folio' => $folio,
        ]);
        $val = $stmt->fetchColumn();

        // Trata ausente/NULL/vacio como "sin XML" (ej. metadata backfilleada sin XML):
        // asi GET /pdf devuelve 404 limpio en vez de intentar generar un PDF vacio.
        return ($val === false || $val === null || $val === '') ? null : (string) $val;
    }

    public function existeAnulacion(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDteRef,
        int $folioRef,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dte_emitido '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = 61 '
            . '  AND tipo_dte_ref = :tref AND folio_ref = :fref '
            . 'LIMIT 1'
        );
        $stmt->execute([
            ':rut'  => $rutEmisor,
            ':amb'  => $ambiente->value,
            ':tref' => $tipoDteRef,
            ':fref' => $folioRef,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function actualizarEstado(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        string $estado,
        ?string $trackId = null,
    ): void {
        $sql = 'UPDATE dte_emitido SET estado = :estado'
            . ($trackId !== null ? ', track_id = :track' : '')
            . ' WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = :tipo AND folio = :folio';

        $params = [
            ':estado' => $estado,
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente->value,
            ':tipo'   => $tipoDte,
            ':folio'  => $folio,
        ];
        if ($trackId !== null) {
            $params[':track'] = $trackId;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * WHERE + params comunes del filtro por periodo (rut+ambiente del servidor,
     * rango de fecha_emision opcional, tipo_dte/folio/receptorRut/estado
     * opcionales).
     *
     * $desde/$hasta nullable: sin ellos (busqueda puntual por folio, M5) no se
     * restringe por fecha. $folio es la busqueda puntual de UN documento (junto
     * a tipo_dte, unico por rut_emisor+ambiente+tipo_dte+folio). $receptorRut
     * es LIKE (busqueda parcial, para el listado M5); $estado es igualdad
     * exacta (no hay catalogo cerrado de valores: vienen de distintas fuentes
     * SII -- 'enviado', 'EPR', 'DOK', 'DNK', etc).
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function filtroPeriodo(
        string $rutEmisor,
        Ambiente $ambiente,
        ?string $desde,
        ?string $hasta,
        ?int $tipoDte,
        ?int $folio = null,
        ?string $receptorRut = null,
        ?string $estado = null,
    ): array {
        $where  = 'rut_emisor = :rut AND ambiente = :amb';
        $params = [':rut' => $rutEmisor, ':amb' => $ambiente->value];
        if ($desde !== null && $hasta !== null) {
            $where .= ' AND fecha_emision BETWEEN :desde AND :hasta';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        }
        if ($tipoDte !== null) {
            $where .= ' AND tipo_dte = :tipo';
            $params[':tipo'] = $tipoDte;
        }
        if ($folio !== null) {
            $where .= ' AND folio = :folio';
            $params[':folio'] = $folio;
        }
        if ($receptorRut !== null && $receptorRut !== '') {
            $where .= ' AND receptor_rut LIKE :receptor';
            $params[':receptor'] = '%' . $receptorRut . '%';
        }
        if ($estado !== null && $estado !== '') {
            $where .= ' AND estado = :estado';
            $params[':estado'] = $estado;
        }
        return [$where, $params];
    }

    /**
     * Lista (paginada) la metadata de los DTE del periodo. NO incluye el XML.
     *
     * @return list<array<string,mixed>>
     */
    public function listarPorPeriodo(
        string $rutEmisor,
        Ambiente $ambiente,
        ?string $desde,
        ?string $hasta,
        ?int $tipoDte,
        int $limit,
        int $offset,
        ?int $folio = null,
        ?string $receptorRut = null,
        ?string $estado = null,
    ): array {
        [$where, $params] = $this->filtroPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $folio, $receptorRut, $estado);
        $stmt = $this->pdo->prepare(
            // glosa_sii (migracion 027) viaja junto al estado a proposito: el
            // codigo del SII son tres letras y la glosa es el texto que las
            // explica. Mostrar "RCT" sin "Rechazado por Error en Caratula"
            // obliga a alguien a ir a buscar que significa, que es exactamente
            // lo que no paso con las 68 facturas exentas.
            'SELECT folio, tipo_dte, fecha_emision, receptor_rut, neto, iva, total, estado, glosa_sii, track_id, folio_ref, tipo_dte_ref '
            . 'FROM dte_emitido WHERE ' . $where
            . ' ORDER BY fecha_emision DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'folio'        => (int) $r['folio'],
            'tipoDte'      => (int) $r['tipo_dte'],
            'fechaEmision' => $r['fecha_emision'],
            'receptorRut'  => $r['receptor_rut'],
            'neto'         => (int) $r['neto'],
            'iva'          => (int) $r['iva'],
            'total'        => (int) $r['total'],
            'estado'       => $r['estado'],
            'glosaSii'     => $r['glosa_sii'],
            'trackId'      => $r['track_id'],
            'folioRef'     => $r['folio_ref'] !== null ? (int) $r['folio_ref'] : null,
            'tipoDteRef'   => $r['tipo_dte_ref'] !== null ? (int) $r['tipo_dte_ref'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Cuenta total de filas del filtro (para paginacion). */
    public function contarPorPeriodo(
        string $rutEmisor,
        Ambiente $ambiente,
        ?string $desde,
        ?string $hasta,
        ?int $tipoDte,
        ?int $folio = null,
        ?string $receptorRut = null,
        ?string $estado = null,
    ): int {
        [$where, $params] = $this->filtroPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $folio, $receptorRut, $estado);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM dte_emitido WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Resumen contable del periodo POR TIPO (sin un total agregado ambiguo: las NC 61
     * restan, asi que el cliente compone segun necesite). Calculado en SQL (SUM/GROUP).
     *
     * @return array{porTipo: array<string, array{cantidad:int, neto:int, iva:int, total:int}>}
     */
    public function resumenPorPeriodo(
        string $rutEmisor,
        Ambiente $ambiente,
        ?string $desde,
        ?string $hasta,
        ?int $tipoDte,
        ?int $folio = null,
        ?string $receptorRut = null,
        ?string $estado = null,
    ): array {
        [$where, $params] = $this->filtroPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $folio, $receptorRut, $estado);

        // LOS RECHAZADOS NO SUMAN. Un envio rechazado se corrige y se reemite,
        // asi que los mismos montos quedan dos veces en la tabla: medido en
        // produccion, 68 rechazados y 68 buenos por las MISMAS ventas.
        //
        // VA SOLO AQUI Y NO EN filtroPeriodo(), aunque el WHERE se comparta con
        // listarPorPeriodo() y contarPorPeriodo(). Esas dos son EL LISTADO, y el
        // listado tiene que seguir mostrando los rechazados: es donde se van a
        // ver. Meter la exclusion en el filtro comun los haria desaparecer de la
        // pantalla, que es lo contrario de lo que se busca.
        $stmt = $this->pdo->prepare(
            'SELECT tipo_dte, COUNT(*) AS n, COALESCE(SUM(neto),0) AS neto, '
            . 'COALESCE(SUM(iva),0) AS iva, COALESCE(SUM(total),0) AS total '
            . 'FROM dte_emitido WHERE ' . $where . EstadoContable::sqlExcluirRechazados()
            . ' GROUP BY tipo_dte'
        );
        $stmt->execute($params);

        $porTipo = [];
        foreach ([33, 61, 56, 39] as $t) {
            $porTipo[(string) $t] = ['cantidad' => 0, 'neto' => 0, 'iva' => 0, 'total' => 0];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porTipo[(string) (int) $r['tipo_dte']] = [
                'cantidad' => (int) $r['n'],
                'neto'     => (int) $r['neto'],
                'iva'      => (int) $r['iva'],
                'total'    => (int) $r['total'],
            ];
        }

        return ['porTipo' => $porTipo];
    }
}
