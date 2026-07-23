<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Psr\Http\Message\ResponseInterface;

/**
 * Consulta el estado de un ENVIO de boleta por su trackid (canal REST de boletas).
 *
 * Endpoint (apicert/api): GET /recursos/v1/boleta.electronica.envio/{rut}-{dv}-{trackid}
 * Headers: Cookie TOKEN, Accept application/json, User-Agent PROG.
 *
 * Respuesta JSON (ejemplo):
 *   { "estado":"EPR", "estadistica":[{"tipo":39,"informados":1,"aceptados":1,
 *     "rechazados":0,"reparos":0}], "detalle_rep_rech":[...] }
 * Estados vistos: REC (recibido), EPR (envio procesado), RSC (rechazado schema).
 * El detalle de rechazos/reparos viene en detalle_rep_rech[].error[].
 * El token debe ser de BOLETA (BoletaAutenticador).
 */
final class BoletaConsultor
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://api.sii.cl',
        'certificacion' => 'https://apicert.sii.cl',
    ];

    private const USER_AGENT = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT)';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * @return array{
     *   estado: string, informados: int, aceptados: int, rechazados: int,
     *   reparos: int, errores: list<string>, raw: array<string,mixed>
     * }
     */
    public function consultarEnvio(
        string $rutEmisor,
        string $trackId,
        string $token,
        Ambiente $ambiente,
    ): array {
        [$num, $dv] = $this->separarRutDv($rutEmisor);

        $url = $this->baseUrl($ambiente)
            . '/recursos/v1/boleta.electronica.envio/'
            . $num . '-' . $dv . '-' . $trackId;

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Cookie'     => 'TOKEN=' . $token,
                    'Accept'     => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con SII (estado boleta): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta (estado boleta): ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle (estado boleta): ' . $e->getMessage(), 0, $e);
        }

        $this->garantizarHttpOk($response);

        $raw = trim((string) $response->getBody());

        if (stripos($raw, 'NO ESTA AUTENTICADO') !== false) {
            throw new SiiAutenticacionException('NO_AUTENTICADO', 'SII: NO ESTA AUTENTICADO (estado boleta)');
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new ConexionException('Respuesta de estado boleta no es JSON. Cuerpo: ' . substr($raw, 0, 1000));
        }

        return $this->resumir($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{estado:string, informados:int, aceptados:int, rechazados:int, reparos:int, errores:list<string>, raw:array<string,mixed>}
     */
    private function resumir(array $data): array
    {
        $informados = $aceptados = $rechazados = $reparos = 0;
        $estadistica = is_array($data['estadistica'] ?? null) ? $data['estadistica'] : [];
        foreach ($estadistica as $e) {
            if (! is_array($e)) {
                continue;
            }
            $informados += (int) ($e['informados'] ?? 0);
            $aceptados  += (int) ($e['aceptados'] ?? 0);
            $rechazados += (int) ($e['rechazados'] ?? 0);
            $reparos    += (int) ($e['reparos'] ?? 0);
        }

        $errores = [];
        $detalle = is_array($data['detalle_rep_rech'] ?? null) ? $data['detalle_rep_rech'] : [];
        foreach ($detalle as $d) {
            if (! is_array($d)) {
                continue;
            }
            $folio = $d['folio'] ?? '-';
            $tipo  = $d['tipo'] ?? '-';
            $errs  = is_array($d['error'] ?? null) ? $d['error'] : [];
            foreach ($errs as $er) {
                if (! is_array($er)) {
                    continue;
                }
                $errores[] = sprintf(
                    'tipo %s folio %s [%s] %s: %s',
                    (string) $tipo,
                    (string) $folio,
                    (string) ($er['seccion'] ?? '?'),
                    (string) ($er['descripcion'] ?? ''),
                    trim((string) ($er['detalle'] ?? '')),
                );
            }
        }

        return [
            'estado'     => (string) ($data['estado'] ?? ''),
            'informados' => $informados,
            'aceptados'  => $aceptados,
            'rechazados' => $rechazados,
            'reparos'    => $reparos,
            'errores'    => $errores,
            'raw'        => $data,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function separarRutDv(string $rut): array
    {
        $limpio = str_replace('.', '', trim($rut));
        if (! str_contains($limpio, '-')) {
            throw new InvalidArgumentException("RUT sin guion: '$rut'");
        }
        [$n, $d] = explode('-', $limpio, 2);
        if ($n === '' || $d === '') {
            throw new InvalidArgumentException("RUT mal formado: '$rut'");
        }
        return [$n, strtoupper($d)];
    }

    private function garantizarHttpOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new ConexionException("SII respondio HTTP $status en consulta de estado boleta");
        }
    }

    private function baseUrl(Ambiente $ambiente): string
    {
        return self::HOSTS[$ambiente->value];
    }
}
