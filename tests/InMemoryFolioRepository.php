<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;

/**
 * Implementacion del FolioRepositoryInterface puramente en memoria, para tests.
 *
 * No es thread-safe ni atomica (PHP CLI es un solo hilo); su unica funcion es
 * verificar que el CONTRATO se comporte como debe: numeracion correlativa,
 * no repetir folios, agotamiento, multi-tenant, bitacora.
 *
 * NO usar en produccion.
 */
final class InMemoryFolioRepository implements FolioRepositoryInterface
{
    /**
     * @var array<string, list<array{desde:int,hasta:int,xml:string,proximo:int,estado:string}>>
     */
    private array $cafs = [];

    /**
     * @var list<array{rut:string,tipo:int,amb:string,folio:int,exitosa:?bool}>
     */
    private array $log = [];

    public function cargarCaf(
        string $rutEmisor,
        TipoDte $tipo,
        Ambiente $ambiente,
        int $desde,
        int $hasta,
        string $xml,
    ): void {
        $k = $this->key($rutEmisor, $tipo, $ambiente);
        $this->cafs[$k] ??= [];
        $this->cafs[$k][] = [
            'desde'   => $desde,
            'hasta'   => $hasta,
            'xml'     => $xml,
            'proximo' => $desde,
            'estado'  => 'activo',
        ];
        usort($this->cafs[$k], static fn ($a, $b) => $a['desde'] <=> $b['desde']);
    }

    public function asignarSiguienteFolio(string $rutEmisor, TipoDte $tipo, Ambiente $ambiente): int
    {
        $k = $this->key($rutEmisor, $tipo, $ambiente);
        foreach ($this->cafs[$k] ?? [] as $i => $caf) {
            if ($caf['estado'] !== 'activo') {
                continue;
            }
            if ($caf['proximo'] > $caf['hasta']) {
                $this->cafs[$k][$i]['estado'] = 'agotado';
                continue;
            }
            $folio = $caf['proximo'];
            $this->cafs[$k][$i]['proximo']++;
            if ($this->cafs[$k][$i]['proximo'] > $caf['hasta']) {
                $this->cafs[$k][$i]['estado'] = 'agotado';
            }
            $this->log[] = [
                'rut'     => $rutEmisor,
                'tipo'    => $tipo->value,
                'amb'     => $ambiente->value,
                'folio'   => $folio,
                'exitosa' => null,
            ];
            return $folio;
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
        $k = $this->key($rutEmisor, $tipo, $ambiente);
        foreach ($this->cafs[$k] ?? [] as $caf) {
            if ($caf['estado'] === 'activo' && $caf['proximo'] <= $caf['hasta']) {
                return $caf['xml'];
            }
        }
        throw new FoliosAgotadosException('Sin CAF activo');
    }

    public function marcarFolioComoUsado(
        string $rutEmisor,
        TipoDte $tipo,
        Ambiente $ambiente,
        int $folio,
        bool $emisionExitosa,
    ): void {
        foreach ($this->log as $i => $entry) {
            if (
                $entry['rut'] === $rutEmisor
                && $entry['tipo'] === $tipo->value
                && $entry['amb'] === $ambiente->value
                && $entry['folio'] === $folio
            ) {
                $this->log[$i]['exitosa'] = $emisionExitosa;
                return;
            }
        }
    }

    /**
     * @return list<array{rut:string,tipo:int,amb:string,folio:int,exitosa:?bool}>
     */
    public function log(): array
    {
        return $this->log;
    }

    private function key(string $rut, TipoDte $tipo, Ambiente $amb): string
    {
        return $rut . '|' . $tipo->value . '|' . $amb->value;
    }
}
