<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use RuntimeException;

/**
 * Los 5 CASO fijos del Set de Prueba de Boleta Electronica que entrega el SII
 * -- UNIVERSAL para cualquier tenant (confirmado: diff byte a byte entre las
 * copias guardadas de EASY AGENDA y sinergia es vacio, sin NUMERO DE ATENCION
 * ni variacion por contribuyente, a diferencia del Set de Pruebas de FACTURA).
 * Por eso esta clase NO parsea ningun archivo subido por el tenant: solo lee
 * la plantilla fija (integration/plantiflex/templates/boleta_set_pruebas_casos.json,
 * copia literal de scripts/emitir_set_boletas_ea.php) y arma los 5
 * DocumentoTributario listos para BoletaFacturador::emitirLote().
 *
 * El receptor tambien es fijo (RUT 66666666-6, "Consumidor Final"): mismo RUT
 * de prueba generico que ya usa SimulacionSetBuilder.
 */
final class BoletaSetPruebasBuilder
{
    public function __construct(
        private readonly string $rutaPlantilla = __DIR__ . '/../../integration/plantiflex/templates/boleta_set_pruebas_casos.json',
    ) {
    }

    /**
     * Los 5 casos tal cual vienen de la plantilla (nombre + detalles crudos),
     * para previsualizar en el panel antes de emitir -- no arma DTOs.
     *
     * @return list<array{nombre:string, detalles:list<array<string,mixed>>}>
     */
    public function casos(): array
    {
        return $this->leerPlantilla()['casos'];
    }

    /**
     * @return list<DocumentoTributario> Las 5 boletas del set fijo, en orden CASO-1..CASO-5.
     */
    public function construirDocumentos(): array
    {
        $plantilla = $this->leerPlantilla();
        $receptor  = new Receptor(
            rut:         $plantilla['receptor']['rut'],
            razonSocial: $plantilla['receptor']['razonSocial'],
        );

        $documentos = [];
        foreach ($plantilla['casos'] as $caso) {
            $detalles = array_map(
                static fn (array $d): Detalle => new Detalle(
                    nombre:         $d['nombre'],
                    cantidad:       (float) $d['cantidad'],
                    precioUnitario: (float) $d['precioUnitario'],
                    exento:         (bool) ($d['exento'] ?? false),
                    unidad:         $d['unidad'] ?? null,
                ),
                $caso['detalles'],
            );

            $documentos[] = new DocumentoTributario(
                tipoDte: TipoDte::BoletaElectronica,
                receptor: $receptor,
                detalles: $detalles,
                montosSonBrutos: true,
                referencias: [[
                    // Ver emitir_set_boletas_ea.php: el manual de boleta NO
                    // lista "SET" entre los TpoDocRef validos (solo 39/41/50/52
                    // y no-tributarios 801-813) -- se omite 'tipoDocumento' a
                    // proposito, queda solo NroLinRef + CodRef + RazonRef.
                    'codigo' => 'SET',
                    'razon'  => $caso['nombre'],
                ]],
            );
        }

        return $documentos;
    }

    /**
     * @return array{receptor: array{rut:string, razonSocial:string}, casos: list<array{nombre:string, detalles:list<array<string,mixed>>}>}
     */
    private function leerPlantilla(): array
    {
        if (! is_file($this->rutaPlantilla)) {
            throw new RuntimeException("BoletaSetPruebasBuilder: no se encontro la plantilla en {$this->rutaPlantilla}");
        }
        $json = json_decode((string) file_get_contents($this->rutaPlantilla), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($json) || ! isset($json['casos'], $json['receptor'])) {
            throw new RuntimeException('BoletaSetPruebasBuilder: plantilla mal formada (faltan casos/receptor).');
        }

        return $json;
    }
}
