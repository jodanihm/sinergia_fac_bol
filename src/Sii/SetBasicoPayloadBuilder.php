<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Dto\CasoSetBasico;
use Plantiflex\FacturacionCl\Dto\SetBasicoPayloadResultado;
use Plantiflex\FacturacionCl\Dto\SetPruebasParseado;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;

/**
 * Construye el body de POST /api/v1/dte/lote a partir de un SetPruebasParseado,
 * reemplazando el armado manual de JSON que hoy hace un humano para certificar
 * el Set Basico. Reusa el mismo formato de payload que ya acepto el SII (ver
 * payload_set_basico_v2.json y tests/SetBasicoPayloadBuilderTest.php).
 *
 * REGLA DE ORO: las glosas de item viajan EXACTAS (tildes/enes) tal como las
 * devuelve SetPruebasParser -- NUNCA se les aplica limpieza/normalizacion
 * ASCII en este camino (Leccion critica: "Cajon" en vez de "Cajón" causo un
 * rechazo real del SII).
 *
 * Solo construye el array; NO llama al SII ni asigna folios (eso lo hace
 * POST /api/v1/dte/lote, que resuelve `refIndiceLote` a TpoDocRef/FolioRef
 * reales una vez asignados los folios -- ver emitirDteLote() en public/index.php).
 */
final class SetBasicoPayloadBuilder
{
    /**
     * Receptor de prueba FIJO para todo el set basico de certificacion del
     * SII: no depende del tenant ni del archivo parseado (el SIISetDePruebas
     * no trae datos de receptor). Es el mismo valor que usan TODOS los
     * scripts de certificacion existentes en el repo (emitir_set_basico_lote.php,
     * emitir_simulacion.php, emitir_nc_caso5.php, etc.) -- convencion del SII
     * para esta etapa, no una decision de este builder.
     */
    private const RECEPTOR = [
        'rut'         => '66666666-6',
        'razonSocial' => 'Cliente de Prueba',
        'giro'        => 'Servicios',
        'direccion'   => 'Calle Falsa 123',
        'comuna'      => 'Santiago',
    ];

    private const TIPO_DTE = [
        'FACTURA'      => 33,
        'NOTA_CREDITO' => 61,
        'NOTA_DEBITO'  => 56,
    ];

    public function construir(SetPruebasParseado $parseado, DateTimeImmutable $fecha): SetBasicoPayloadResultado
    {
        $fechaStr = $fecha->format('Y-m-d');
        $errores = [];
        $documentos = [];
        /** @var array<int,int> $indicePorNumeroCaso numeroCaso del SII -> indice en $documentos */
        $indicePorNumeroCaso = [];
        /** @var array<int,int> $tipoDtePorIndice indice en $documentos -> tipoDte (33/61/56) */
        $tipoDtePorIndice = [];

        foreach ($parseado->casos as $caso) {
            $indiceActual = count($documentos);
            $referenciaSet = [
                'tipoDocumento' => 'SET',
                'fecha'         => $fechaStr,
                'razon'         => sprintf('CASO %d-%d', $parseado->numeroAtencionSetBasico, $caso->numeroCaso),
            ];

            if ($caso->tipoDocumento === 'FACTURA') {
                [$detalles, $error] = $this->mapearItems($caso);
                if ($error !== null) {
                    $errores[] = $error;
                    continue;
                }

                $documento = [
                    'tipoDte'  => self::TIPO_DTE['FACTURA'],
                    'receptor' => self::RECEPTOR,
                    'detalles' => $detalles,
                ];
                if ($caso->descuentoGlobalPct !== null) {
                    $documento['descuentoGlobalPct'] = $caso->descuentoGlobalPct;
                }
                $documento['referencias'] = [$referenciaSet];

                $documentos[] = $documento;
                $indicePorNumeroCaso[$caso->numeroCaso] = $indiceActual;
                $tipoDtePorIndice[$indiceActual] = self::TIPO_DTE['FACTURA'];
                continue;
            }

            // NOTA_CREDITO / NOTA_DEBITO: siempre referencian un caso ANTERIOR
            // ya construido (mismo orden causal que exige POST /api/v1/dte/lote
            // para refIndiceLote).
            if ($caso->referenciaCaso === null || ! isset($indicePorNumeroCaso[$caso->referenciaCaso])) {
                $errores[] = sprintf(
                    'Caso %d (%s): no se pudo resolver el caso referenciado (%s); requiere revision manual.',
                    $caso->numeroCaso,
                    $caso->tipoDocumento,
                    $caso->referenciaCaso === null ? 'el archivo no trae REFERENCIA' : "el caso {$caso->referenciaCaso} no se construyo antes"
                );
                continue;
            }

            $indiceRef = $indicePorNumeroCaso[$caso->referenciaCaso];
            $razon = $caso->razonReferencia ?? '';
            $razonMayus = strtoupper($razon);

            if ($caso->items !== []) {
                // Regla (b): devolucion PARCIAL -- el archivo trae cantidad
                // pero no precio; se toma precioUnitario/descuentoPorcentaje
                // del item CON EL MISMO NOMBRE del caso ya construido que se
                // referencia. Si algun nombre no calza, no se inventa: error.
                [$detalles, $error] = $this->construirDetallesDevolucionParcial($caso, $documentos[$indiceRef]['detalles']);
                if ($error !== null) {
                    $errores[] = $error;
                    continue;
                }
                $codigo = TipoAnulacion::CorrigeMonto;
            } elseif (str_contains($razonMayus, 'ANULA') && $tipoDtePorIndice[$indiceRef] === self::TIPO_DTE['FACTURA']) {
                // Regla (c): anulacion TOTAL de una FACTURA -- copia EXACTA
                // del detalle ya construido del caso referenciado. NUNCA un
                // generico en monto 0: asi quedo mal la primera vez (rechazo
                // SII REF-2-780 "Anulacion presenta diferencia de monto con
                // documento referenciado").
                $detalles = $documentos[$indiceRef]['detalles'];
                $codigo = TipoAnulacion::AnulaTotal;
            } elseif (str_contains($razonMayus, 'ANULA')) {
                // Regla (a): anula un documento que NO es factura (ej. una ND
                // que anula una NC sin montos reales) -- linea generica en
                // monto 0, no hay nada que copiar con sentido.
                $detalles = [['nombre' => $razon, 'cantidad' => 1, 'precioUnitario' => 0]];
                $codigo = TipoAnulacion::AnulaTotal;
            } elseif (str_contains($razonMayus, 'CORRIGE')) {
                // Regla (a): corrige un dato no monetario -- linea generica en monto 0.
                $detalles = [['nombre' => $razon, 'cantidad' => 1, 'precioUnitario' => 0]];
                $codigo = TipoAnulacion::CorrigeTexto;
            } else {
                $errores[] = sprintf(
                    'Caso %d (%s): razonReferencia "%s" no contiene "ANULA" ni "CORRIGE" y no trae items -- '
                    . 'no se puede determinar como construir el detalle sin adivinar. Requiere revision manual.',
                    $caso->numeroCaso,
                    $caso->tipoDocumento,
                    $razon
                );
                continue;
            }

            $documentos[] = [
                'tipoDte'     => self::TIPO_DTE[$caso->tipoDocumento],
                'receptor'    => self::RECEPTOR,
                'detalles'    => $detalles,
                'referencias' => [
                    $referenciaSet,
                    [
                        'refIndiceLote' => $indiceRef,
                        'codigo'        => (string) $codigo->value,
                        'razon'         => $razon,
                    ],
                ],
            ];
            $indicePorNumeroCaso[$caso->numeroCaso] = $indiceActual;
            $tipoDtePorIndice[$indiceActual] = self::TIPO_DTE[$caso->tipoDocumento];
        }

        if ($errores !== []) {
            return new SetBasicoPayloadResultado(null, $errores);
        }

        return new SetBasicoPayloadResultado(['documentos' => $documentos], []);
    }

    /** @return array{0: ?list<array<string,mixed>>, 1: ?string} */
    private function mapearItems(CasoSetBasico $caso): array
    {
        $detalles = [];
        foreach ($caso->items as $item) {
            if ($item->precioUnitario === null) {
                return [null, sprintf(
                    'Caso %d: el item "%s" no trae precioUnitario en el archivo; no se puede construir la factura sin inventarlo.',
                    $caso->numeroCaso,
                    $item->nombre
                )];
            }

            $detalle = [
                'nombre'         => $item->nombre,
                'cantidad'       => $item->cantidad,
                'precioUnitario' => $item->precioUnitario,
            ];
            if ($item->descuentoPorcentaje !== null) {
                $detalle['descuentoPorcentaje'] = $item->descuentoPorcentaje;
            }
            if ($item->exento) {
                $detalle['exento'] = true;
            }
            $detalles[] = $detalle;
        }

        return [$detalles, null];
    }

    /**
     * @param list<array<string,mixed>> $detallesReferencia detalles YA CONSTRUIDOS del documento (factura) referenciado
     * @return array{0: ?list<array<string,mixed>>, 1: ?string}
     */
    private function construirDetallesDevolucionParcial(CasoSetBasico $caso, array $detallesReferencia): array
    {
        $porNombre = [];
        foreach ($detallesReferencia as $d) {
            $porNombre[$d['nombre']] = $d;
        }

        $detalles = [];
        foreach ($caso->items as $item) {
            if (! isset($porNombre[$item->nombre])) {
                return [null, sprintf(
                    'Caso %d: el item "%s" no calza por nombre con ningun item del caso referenciado; '
                    . 'no se puede inferir su precio unitario sin inventarlo.',
                    $caso->numeroCaso,
                    $item->nombre
                )];
            }

            $ref = $porNombre[$item->nombre];
            $detalle = [
                'nombre'         => $item->nombre,
                'cantidad'       => $item->cantidad,
                'precioUnitario' => $ref['precioUnitario'] ?? 0,
            ];
            if (isset($ref['descuentoPorcentaje'])) {
                $detalle['descuentoPorcentaje'] = $ref['descuentoPorcentaje'];
            }
            if (isset($ref['exento'])) {
                $detalle['exento'] = $ref['exento'];
            }
            $detalles[] = $detalle;
        }

        return [$detalles, null];
    }
}
