<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use Plantiflex\FacturacionCl\Contracts\DteEmitidoRepositoryInterface;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Implementacion del DteEmitidoRepositoryInterface puramente en memoria, para
 * tests. Su unica funcion es verificar que cada fila persistida trae los
 * datos correctos (folio, trackId, montos POR DOCUMENTO, etc.) -- NO usar en
 * produccion.
 */
final class InMemoryDteEmitidoRepository implements DteEmitidoRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    private array $filas = [];

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
    ): void {
        $fila = [
            'rutEmisor'    => $rutEmisor,
            'ambiente'     => $ambiente->value,
            'tipoDte'      => $tipoDte,
            'folio'        => $folio,
            'trackId'      => $trackId,
            'estado'       => $estado,
            'xml'          => $xml,
            'fechaEmision' => $fechaEmision,
            'neto'         => $neto,
            'iva'          => $iva,
            'total'        => $total,
            'receptorRut'  => $receptorRut,
            'folioRef'     => $folioRef,
            'tipoDteRef'   => $tipoDteRef,
        ];

        foreach ($this->filas as $i => $f) {
            if (
                $f['rutEmisor'] === $rutEmisor && $f['ambiente'] === $ambiente->value
                && $f['tipoDte'] === $tipoDte && $f['folio'] === $folio
            ) {
                $this->filas[$i] = $fila;
                return;
            }
        }
        $this->filas[] = $fila;
    }

    public function obtenerXml(string $rutEmisor, Ambiente $ambiente, int $tipoDte, int $folio): ?string
    {
        foreach ($this->filas as $f) {
            if (
                $f['rutEmisor'] === $rutEmisor && $f['ambiente'] === $ambiente->value
                && $f['tipoDte'] === $tipoDte && $f['folio'] === $folio
            ) {
                return $f['xml'];
            }
        }
        return null;
    }

    public function existeAnulacion(string $rutEmisor, Ambiente $ambiente, int $tipoDteRef, int $folioRef): bool
    {
        foreach ($this->filas as $f) {
            if (
                $f['rutEmisor'] === $rutEmisor && $f['ambiente'] === $ambiente->value
                && $f['tipoDteRef'] === $tipoDteRef && $f['folioRef'] === $folioRef
            ) {
                return true;
            }
        }
        return false;
    }

    public function actualizarEstado(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        string $estado,
        ?string $trackId = null,
    ): void {
        foreach ($this->filas as $i => $f) {
            if (
                $f['rutEmisor'] === $rutEmisor && $f['ambiente'] === $ambiente->value
                && $f['tipoDte'] === $tipoDte && $f['folio'] === $folio
            ) {
                $this->filas[$i]['estado'] = $estado;
                if ($trackId !== null) {
                    $this->filas[$i]['trackId'] = $trackId;
                }
                return;
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function todas(): array
    {
        return $this->filas;
    }
}
