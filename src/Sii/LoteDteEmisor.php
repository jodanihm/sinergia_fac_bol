<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;

/**
 * Asigna folios, resuelve referencias intra-lote (refIndiceLote) y emite un
 * lote de documentos en un solo EnvioDTE.
 *
 * Misma logica que el bucle de asignacion de folios + resolucion de
 * refIndiceLote de emitirDteLote() (public/index.php, POST /api/v1/dte/lote),
 * extraida aqui para poder invocarla DIRECTO desde PHP (sin HTTP interno)
 * desde el panel -- ver handleSetPruebasEmitirPost(). NO se toco
 * emitirDteLote() en esta tarea (riesgo de regresion en un endpoint ya
 * probado, fuera del alcance): queda una duplicacion pequena y deliberada
 * entre ambos: documentarla aqui para un futuro refactor que unifique los dos
 * si se decide.
 *
 * Esta clase NO valida forma de entrada (tipoDte valido, receptor completo,
 * etc.): asume que $documentos ya viene bien formado (por ejemplo, de
 * SetBasicoPayloadBuilder::construir(), que solo genera casos sin
 * ambiguedad) y confia en que los DTOs (Detalle/Receptor/DocumentoTributario)
 * lanzan DocumentoInvalidoException si algo igual esta mal.
 */
final class LoteDteEmisor
{
    public function __construct(
        private readonly SiiDirectoFacturador $facturador,
        private readonly FolioRepositoryInterface $folios,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $documentos misma forma que 'documentos' del body de POST /api/v1/dte/lote
     * @return array{trackId: string, documentos: list<array{tipoDte:int, folio:int}>}
     */
    public function emitir(array $documentos, Credenciales $cred, DateTimeImmutable $fecha): array
    {
        $fechaStr = $fecha->format('Y-m-d');

        $docs     = [];
        $emitidos = [];
        foreach ($documentos as $v) {
            $tipo  = TipoDte::from((int) $v['tipoDte']);
            $folio = $this->folios->asignarSiguienteFolio($cred->rutEmisor, $tipo, $cred->ambiente);
            $r     = $v['receptor'];

            // Resolver referencias intra-lote: refIndiceLote -> TpoDocRef/
            // FolioRef/FchRef reales del documento ya emitido antes en este
            // mismo lote (mismo orden causal que exige SetBasicoPayloadBuilder).
            $referencias = [];
            foreach (($v['referencias'] ?? []) as $ref) {
                if (is_array($ref) && array_key_exists('refIndiceLote', $ref)) {
                    $k = $ref['refIndiceLote'];
                    unset($ref['refIndiceLote']);
                    $ref['tipoDocumento'] = (string) $emitidos[$k]['tipoDte'];
                    $ref['folio']         = $emitidos[$k]['folio'];
                    $ref['fecha']         = $fechaStr;
                }
                $referencias[] = $ref;
            }

            $docs[] = new DocumentoTributario(
                tipoDte: $tipo,
                receptor: new Receptor(
                    rut: $r['rut'],
                    razonSocial: $r['razonSocial'],
                    giro: $r['giro'],
                    direccion: $r['direccion'],
                    comuna: $r['comuna'],
                ),
                detalles: array_map(
                    static fn (array $d): Detalle => new Detalle(
                        $d['nombre'],
                        (float) $d['cantidad'],
                        (float) $d['precioUnitario'],
                        exento: (bool) ($d['exento'] ?? false),
                        descuentoPorcentaje: (float) ($d['descuentoPorcentaje'] ?? 0),
                    ),
                    $v['detalles'],
                ),
                montosSonBrutos: (bool) ($v['montosSonBrutos'] ?? false),
                folio: $folio,
                fechaEmision: $fecha,
                referencias: $referencias,
                descuentoGlobalPct: $v['descuentoGlobalPct'] ?? null,
            );
            $emitidos[] = ['tipoDte' => (int) $v['tipoDte'], 'folio' => $folio];
        }

        $res = $this->facturador->emitirLote($docs, $cred);

        return ['trackId' => $res['trackId'], 'documentos' => $emitidos];
    }
}
