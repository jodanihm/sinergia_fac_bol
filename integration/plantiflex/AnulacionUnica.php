<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;

/**
 * Una sola nota de credito de anulacion por documento, aunque lleguen dos
 * pedidos a la vez (doble clic, reintento tras un timeout, dos pestanas).
 *
 * EL PROBLEMA. POST /api/v1/dte/{tipo}/{folio}/anular solo miraba
 * existeAnulacion() -- un SELECT -- antes de emitir. Entre ese SELECT y el
 * INSERT de la NC en dte_emitido pasan la consulta al SII y el envio completo:
 * segundos, a veces minutos. Dos pedidos dentro de esa ventana veian los dos
 * "no hay NC" y emitian dos NC con CodRef=1 sobre el mismo documento, cada una
 * con su folio. El debito fiscal queda rebajado dos veces.
 *
 * EL MECANISMO NO ES NUEVO: es dte_idempotencia y MySqlIdempotenciaRepository,
 * los mismos de la emision -- el INSERT contra la PK es el candado. Lo unico
 * distinto es DE DONDE SALE LA CLAVE. En la emision la manda el cliente
 * (Idempotency-Key) y un doble clic con dos claves distintas pasaria igual.
 * Aqui la clave se DERIVA del documento anulado: "anulacion:{tipo}:{folio}".
 * Es el documento el que no puede anularse dos veces, no el pedido. El
 * prefijo esta reservado: los endpoints de emision rechazan una
 * Idempotency-Key que empiece asi.
 *
 * EL TTL ES MAS LARGO QUE EL DE LA EMISION (15 min contra 5). Un claim sin
 * completar se da por muerto al cumplir el TTL, y el siguiente pedido emite.
 * Si la primera emision seguia viva, eso es exactamente la doble NC que se
 * quiere evitar. El peor caso de una emision real (token + subida con 4
 * reintentos de 60 s) ronda los 366 s y ya pasa los 300 de la emision; aqui se
 * deja holgura. Ademas, antes de emitir tras reactivar, se vuelve a preguntar
 * si el documento ya quedo anulado.
 *
 * LOS DOS CAMINOS COMPITEN POR EL MISMO CANDADO. Una NC con CodRef=1 tambien
 * puede llegar por la emision normal -- POST /api/v1/dte y /api/v1/dte/lote,
 * que es por donde anula el formulario del panel. Esos endpoints toman la
 * misma clave con emitirSinPisarse(), pero SOLO MIENTRAS LA NC ESTA EN CURSO:
 * al terminar la sueltan. No deciden si el documento "ya esta anulado", porque
 * hoy no hay una regla correcta para eso (existeAnulacion() no mira el CodRef
 * y no sabe de una ND que revierte una anulacion). Asi se cierra la carrera
 * entre los dos caminos sin inventar una regla de negocio.
 */
final class AnulacionUnica
{
    public const PREFIJO_CLAVE = 'anulacion:';

    public const TTL_SEGUNDOS = 900;

    public function __construct(private readonly MySqlIdempotenciaRepository $idem)
    {
    }

    public static function clave(int $tipoDte, int $folio): string
    {
        return self::PREFIJO_CLAVE . $tipoDte . ':' . $folio;
    }

    /**
     * Los documentos que ANULA una nota, leidos de sus referencias tal como
     * llegan al motor: solo si la nota es una NC (61), y solo las referencias
     * CodRef=1 a un documento por tipo y folio. Las referencias intra-lote
     * (refIndiceLote) no cuentan: apuntan a un documento del mismo sobre, que
     * todavia no existe y nadie mas puede estar anulando.
     *
     * @param array<mixed> $referencias
     * @return list<array{0:int, 1:int}> pares [tipo, folio], sin repetidos
     */
    public static function documentosQueAnula(int $tipoDte, array $referencias): array
    {
        if ($tipoDte !== 61) {
            return [];
        }
        $docs = [];
        foreach ($referencias as $ref) {
            if (! is_array($ref) || array_key_exists('refIndiceLote', $ref)) {
                continue;
            }
            $codigo = $ref['codigo'] ?? null;
            $tipo   = $ref['tipoDocumento'] ?? null;
            $folio  = $ref['folio'] ?? null;
            if (! is_numeric($codigo) || (int) $codigo !== 1 || ! is_numeric($tipo) || ! is_numeric($folio) || (int) $folio <= 0) {
                continue;
            }
            $docs[(int) $tipo . ':' . (int) $folio] = [(int) $tipo, (int) $folio];
        }
        return array_values($docs);
    }

    /**
     * Corre $emitir sosteniendo el candado de cada documento que la emision
     * anula, para que /anular no emita otra NC sobre ellos al mismo tiempo (ni
     * otra emision que los anule).
     *
     * Toma los candados antes de emitir. Si alguno esta ocupado por una
     * anulacion EN CURSO, suelta los que ya tomo y lanza
     * AnulacionEnCursoException sin emitir nada. Un candado COMPLETADO -- el de
     * un /anular que ya termino -- no es una anulacion en curso: se deja como
     * esta y la emision sigue, igual que antes de existir el candado.
     *
     * Al terminar los suelta. Si $emitir lanza, los suelta solo cuando es seguro
     * que no quedo una NC viva en el SII (mismo criterio que ejecutar()); ante
     * cualquier otro error quedan hasta el TTL.
     *
     * @template T
     * @param list<array{0:int, 1:int}> $documentos pares [tipo, folio]
     * @param callable(): T $emitir
     * @return T
     */
    public function emitirSinPisarse(string $rutEmisor, Ambiente $ambiente, array $documentos, callable $emitir): mixed
    {
        $tomadas = $this->tomar($rutEmisor, $ambiente, $documentos);
        try {
            $resultado = $emitir();
        } catch (SiiAutenticacionException | FoliosAgotadosException | EnvioRechazadoException $e) {
            $this->soltar($rutEmisor, $ambiente, $tomadas);
            throw $e;
        }
        $this->soltar($rutEmisor, $ambiente, $tomadas);
        return $resultado;
    }

    /**
     * @param list<array{0:int, 1:int}> $documentos
     * @return list<string> las claves que quedaron tomadas por este pedido
     */
    private function tomar(string $rutEmisor, Ambiente $ambiente, array $documentos): array
    {
        $claves = [];
        foreach ($documentos as [$tipo, $folio]) {
            $claves[self::clave($tipo, $folio)] = [$tipo, $folio];
        }
        // Orden fijo: dos pedidos que se cruzan chocan en el mismo documento
        // primero, en vez de tomar cada uno una parte.
        ksort($claves);

        $tomadas = [];
        foreach ($claves as $clave => [$tipo, $folio]) {
            if ($this->idem->reclamar($rutEmisor, $ambiente, $clave)) {
                $tomadas[] = $clave;
                continue;
            }
            $previo = $this->idem->obtener($rutEmisor, $ambiente, $clave);
            if ($previo !== null && $previo['httpStatus'] !== null) {
                continue;
            }
            if ($this->idem->reactivarSiMuerto($rutEmisor, $ambiente, $clave, self::TTL_SEGUNDOS)) {
                $tomadas[] = $clave;
                continue;
            }
            $this->soltar($rutEmisor, $ambiente, $tomadas);
            throw new AnulacionEnCursoException($tipo, $folio);
        }
        return $tomadas;
    }

    /** @param list<string> $claves */
    private function soltar(string $rutEmisor, Ambiente $ambiente, array $claves): void
    {
        foreach ($claves as $clave) {
            $this->idem->liberar($rutEmisor, $ambiente, $clave);
        }
    }

    /**
     * Emite la NC solo si este pedido gana el candado del documento.
     *
     * $yaAnulado se consulta DESPUES de ganar el candado: es la segunda mirada
     * que cierra el caso de un intento anterior que emitio y murio antes de
     * completar la idempotencia.
     *
     * $emitir devuelve [folio de la NC, cuerpo de la respuesta 201]. Si lanza,
     * la excepcion sube tal cual. Antes, el candado se suelta SOLO si es seguro
     * que no quedo una NC viva en el SII: autenticacion fallida o sin folios
     * (antes de asignar folio), o NC rechazada por el SII. Ante cualquier otro
     * error -- un timeout en plena subida -- no se sabe, y el candado se queda
     * hasta el TTL.
     *
     * @param callable(): bool $yaAnulado
     * @param callable(): array{0:int, 1:array<string,mixed>} $emitir
     * @return array{resultado: 'emitida'|'repetida'|'en_proceso'|'ya_anulada', httpStatus: int, payload: array<string,mixed>}
     */
    public function ejecutar(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        callable $yaAnulado,
        callable $emitir,
    ): array {
        $clave = self::clave($tipoDte, $folio);

        if (! $this->idem->reclamar($rutEmisor, $ambiente, $clave)) {
            $previo = $this->idem->obtener($rutEmisor, $ambiente, $clave);
            if ($previo !== null && $previo['httpStatus'] !== null) {
                return [
                    'resultado'  => 'repetida',
                    'httpStatus' => $previo['httpStatus'],
                    'payload'    => (array) json_decode((string) $previo['respuestaJson'], true),
                ];
            }
            if (! $this->idem->reactivarSiMuerto($rutEmisor, $ambiente, $clave, self::TTL_SEGUNDOS)) {
                return [
                    'resultado'  => 'en_proceso',
                    'httpStatus' => 409,
                    'payload'    => ['error' => 'ya hay una anulacion en curso para este documento'],
                ];
            }
        }

        if ($yaAnulado()) {
            $this->idem->liberar($rutEmisor, $ambiente, $clave);
            return [
                'resultado'  => 'ya_anulada',
                'httpStatus' => 409,
                'payload'    => ['error' => 'el documento ya esta anulado o no es anulable'],
            ];
        }

        try {
            [$folioNc, $payload] = $emitir();
        } catch (SiiAutenticacionException | FoliosAgotadosException | EnvioRechazadoException $e) {
            $this->idem->liberar($rutEmisor, $ambiente, $clave);
            throw $e;
        }

        $this->idem->completar(
            $rutEmisor,
            $ambiente,
            $clave,
            61,
            $folioNc,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            201,
        );

        return ['resultado' => 'emitida', 'httpStatus' => 201, 'payload' => $payload];
    }
}
