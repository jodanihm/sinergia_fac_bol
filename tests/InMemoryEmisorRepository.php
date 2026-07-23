<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\DatosEmisorNoEncontradosException;

/**
 * Implementacion en memoria del EmisorRepositoryInterface para tests.
 * NO usar en produccion.
 */
final class InMemoryEmisorRepository implements EmisorRepositoryInterface
{
    /** @var array<string, Certificado> */
    private array $certificados = [];

    /** @var array<string, DatosEmisor> */
    private array $datos = [];

    public function cargarCertificado(string $rutEmisor, Ambiente $ambiente, Certificado $cert): void
    {
        $this->certificados[$this->key($rutEmisor, $ambiente)] = $cert;
    }

    public function cargarDatos(string $rutEmisor, Ambiente $ambiente, DatosEmisor $datos): void
    {
        $this->datos[$this->key($rutEmisor, $ambiente)] = $datos;
    }

    public function obtenerCertificado(string $rutEmisor, Ambiente $ambiente): Certificado
    {
        return $this->certificados[$this->key($rutEmisor, $ambiente)]
            ?? throw new CertificadoNoEncontradoException(
                "Sin certificado para $rutEmisor / {$ambiente->value}"
            );
    }

    public function obtenerDatosEmisor(string $rutEmisor, Ambiente $ambiente): DatosEmisor
    {
        return $this->datos[$this->key($rutEmisor, $ambiente)]
            ?? throw new DatosEmisorNoEncontradosException(
                "Sin datos de emisor para $rutEmisor / {$ambiente->value}"
            );
    }

    private function key(string $rut, Ambiente $amb): string
    {
        return $rut . '|' . $amb->value;
    }
}
