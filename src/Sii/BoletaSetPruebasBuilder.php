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
                    // "SET" VA EN TpoDocRef. NO LO SAQUES.
                    //
                    // El instructivo del SII (inst_set_pruebas.pdf, punto I.6)
                    // lo pide textualmente:
                    //
                    //   "En la primera linea de referencia de cada DTE del set
                    //    de prueba debe indicar: el texto 'SET' como 'Tipo de
                    //    Documento de Referencia' y el texto 'CASO xxxxx-x' en
                    //    el campo 'Razon referencia'"
                    //
                    // Aca hubo un error que costo siete intentos: este bloque
                    // decia que se omitia 'tipoDocumento' a proposito, porque el
                    // manual de boleta no lista "SET" entre los TpoDocRef
                    // tributarios validos (39/41/50/52 y 801-813). El HECHO era
                    // cierto, la CONCLUSION no: "SET" es un valor especial de
                    // certificacion y el instructivo pide ponerlo ahi igual.
                    //
                    // Sin TpoDocRef, la revision del set responde "El Documento
                    // no esta en el envio" con Tipo Doc. 00 y Folio 0: son los
                    // dos campos que el validador busca EN LA REFERENCIA para
                    // saber a que CASO pertenece cada boleta, y los encuentra
                    // vacios. El 00/0 nunca hablo del documento.
                    //
                    // FolioRef no lo pone este arreglo: lo agrega
                    // DteXmlBuilder::buildReferencia() al ver tipoDocumento
                    // 'SET', con el folio PROPIO de la boleta.
                    'tipoDocumento' => 'SET',

                    // CodRef se MANTIENE ademas de TpoDocRef. Los dos documentos
                    // que el SII nos dio piden cosas distintas y no se puede
                    // elegir uno sin desobedecer al otro: el punto I.6 pide
                    // TpoDocRef, y el archivo del set entregado a este
                    // contribuyente (sinergia/Set Prueba Boletas.txt, seccion
                    // OBSERVACIONES GENERALES) da como ejemplo "<CodRef> SET".
                    // Llevar los dos ya se probo que pasa el esquema de boleta:
                    // envio_boleta_combined_folio_128_track_28119455.xml fue
                    // aceptado con esta misma forma (lo que fallo ahi fue el
                    // canal, pangal en vez de maullin, no la referencia).
                    'codigo' => 'SET',

                    // GUION Y NO ESPACIO, copiado tal cual del archivo del set.
                    // El instructivo escribe "CASO xxxxx-x" con espacio, pero esa
                    // es una plantilla cuyo hueco es el numero de caso (ej.
                    // "1062-1") y el set de boleta no tiene un numero asi: titula
                    // sus casos "CASO-1".."CASO-5" y su propio ejemplo de
                    // RazonRef dice "CASO-1". El mismo archivo instruye que "los
                    // caracteres deben estar informados tal cual se encuentran
                    // en el Set", asi que manda el documento concreto por sobre
                    // la plantilla generica. Viene de la plantilla, no escrito
                    // aca, justamente para que sea copia y no transcripcion.
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
