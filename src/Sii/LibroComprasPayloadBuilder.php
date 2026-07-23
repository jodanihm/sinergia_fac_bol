<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Dto\LibroPayloadResultado;
use Plantiflex\FacturacionCl\Dto\SetPruebasParseado;

/**
 * Construye el body de POST /api/v1/libro (Libro de Compras) a partir de
 * SetPruebasParseado->casosLibroCompras: los documentos de PROVEEDORES que el
 * propio SII dicta en el archivo, no algo que el tenant emitio.
 *
 * Las 3 reglas de casos especiales (IVA uso comun / IVA no recuperable /
 * retencion total) replican el docblock de LineaLibro.php lineas 16-26 y el
 * manual oficial del SII (docs/40_Casos_Especiales_Registro_Documentos_IECV.pdf),
 * ya validadas en produccion (payload_libro_compras_v2.json, aceptado LOK/LTC).
 *
 * DESVIACION respecto al enunciado de esta tarea (documentada aqui, no
 * forzada): el payload realmente aceptado por el SII trae mntIva con el monto
 * REAL calculado (nunca 0) en los 3 casos especiales -- es LibroXmlBuilder
 * quien fuerza MntIVA=0 en el XML final (mntIvaEfectivo()), no el payload de
 * entrada. Este builder replica el payload real: mntIva SIEMPRE es el monto
 * real, salvo en retencion total donde el propio SII exige que sea el monto
 * completo (eso si coincide con el enunciado).
 *
 * Referencias entre documentos del libro (ej. "NOTA DE CREDITO POR DESCUENTO
 * A FACTURA 234", folioReferenciado=234 en CasoLibroComprasSetPruebas): NO
 * tienen correlato en el payload de /api/v1/libro. Confirmado revisando
 * LineaLibro.php: no existe ningun campo de referencia/folio-referenciado en
 * el DTO, y el payload historico aceptado (payload_libro_compras_v2.json,
 * linea del folio 451) tampoco lo incluye. El Libro IECV no encadena
 * documentos como el EnvioDTE; folioReferenciado del parser queda sin uso en
 * este camino (es informativo para el preview del panel, no para la emision).
 */
final class LibroComprasPayloadBuilder
{
    /**
     * RUT/razon social de PRUEBA para el proveedor: el archivo del SII NO
     * trae el RUT real de los proveedores del set de pruebas (confirmado en
     * una consulta anterior de esta sesion). Se usa el MISMO valor generico
     * que ya acepto el SII en produccion (payload_libro_compras_v2.json),
     * documentado explicitamente como generico -- no es el RUT de un
     * proveedor real de este tenant.
     */
    private const PROVEEDOR_RUT = '55555555-5';
    private const PROVEEDOR_RAZON_SOCIAL = 'Proveedor de Prueba';

    private const TASA_IVA = 0.19;

    /** Texto EXACTO de tipoDocumentoTexto (tal como lo entrega el archivo/parser) -> codigo TpoDoc del SII. */
    private const TPO_DOC = [
        'FACTURA'                       => 30,
        'FACTURA ELECTRONICA'           => 33,
        'NOTA DE CREDITO'               => 60,
        'NOTA DE CREDITO ELECTRONICA'   => 61,
        'FACTURA DE COMPRA ELECTRONICA' => 46,
    ];

    public function construir(SetPruebasParseado $parseado, DateTimeImmutable $fecha): LibroPayloadResultado
    {
        if ($parseado->casosLibroCompras === []) {
            return new LibroPayloadResultado(null, ['El archivo no trae casos de Libro de Compras.']);
        }
        if ($parseado->numeroAtencionLibroCompras === null) {
            return new LibroPayloadResultado(null, ['El archivo no trae el numero de atencion del Libro de Compras.']);
        }

        $errores = [];
        $lineas  = [];
        foreach ($parseado->casosLibroCompras as $caso) {
            $tpoDoc = self::TPO_DOC[$caso->tipoDocumentoTexto] ?? null;
            if ($tpoDoc === null) {
                $errores[] = sprintf(
                    'Folio %d: tipo de documento "%s" no reconocido; no se puede mapear a TpoDoc sin adivinar.',
                    $caso->folio,
                    $caso->tipoDocumentoTexto
                );
                continue;
            }

            $mntNeto = $caso->montoAfecto;
            $mntIva  = (int) round($mntNeto * self::TASA_IVA);

            $linea = [
                'tpoDoc'         => $tpoDoc,
                'nroDoc'         => $caso->folio,
                'fecha'          => $fecha->format('Y-m-d'),
                'rutContraparte' => self::PROVEEDOR_RUT,
                'razonSocial'    => self::PROVEEDOR_RAZON_SOCIAL,
                'mntExe'         => $caso->montoExento,
                'mntNeto'        => $mntNeto,
            ];

            if ($caso->retencionTotalIva) {
                // Regla (c): mntIva lleva el monto COMPLETO; mntTotal lo
                // descuenta (se resta el IVA retenido). Este caso NO lo
                // fuerza el motor: si el builder lo arma mal, el SII rechaza
                // con "Monto Total No Cuadra" / IVA retenido mal informado.
                $linea['mntIva']      = $mntIva;
                $linea['mntTotal']    = $mntNeto;
                $linea['codOtroImp']  = 15;
                $linea['mntOtroImp']  = $mntIva;
                $linea['tasaOtroImp'] = 19;
            } else {
                $linea['mntIva']   = $mntIva;
                $linea['mntTotal'] = $caso->montoExento + $mntNeto + $mntIva;
                if ($caso->ivaUsoComun) {
                    $linea['ivaUsoComun'] = $mntIva;
                } elseif ($caso->ivaNoRecuperable) {
                    $linea['codIvaNoRec'] = 4;
                    $linea['mntIvaNoRec'] = $mntIva;
                }
            }

            $lineas[] = $linea;
        }

        if ($errores !== []) {
            return new LibroPayloadResultado(null, $errores);
        }

        return new LibroPayloadResultado([
            'tipoOperacion'          => 'COMPRA',
            'periodoTributario'      => $fecha->format('Y-m'),
            'tipoLibro'              => 'ESPECIAL',
            'tipoEnvio'              => 'TOTAL',
            'folioNotificacion'      => $parseado->numeroAtencionLibroCompras,
            'factorProporcionalidad' => $parseado->factorProporcionalidadIvaUsoComun,
            'lineas'                 => $lineas,
        ], []);
    }
}
