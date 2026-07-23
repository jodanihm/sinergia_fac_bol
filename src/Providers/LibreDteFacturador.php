<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Plantiflex\FacturacionCl\Contracts\FacturadorInterface;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\EstadoDte;
use Plantiflex\FacturacionCl\Dto\ResultadoEmision;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\AnulacionNoSoportadaException;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Exceptions\EmisionRechazadaException;
use Psr\Http\Message\ResponseInterface;
use ValueError;

/**
 * Implementacion del FacturadorInterface usando la API REST de LibreDTE.
 *
 * Se mantiene desacoplada del SDK oficial: solo Guzzle + JSON.
 *
 * NOTA: las rutas y forma exacta del payload de LibreDTE deben verificarse
 * contra la documentacion vigente. Los puntos sensibles estan aislados en
 * metodos privados (buildEmisionPayload, endpointEmision, etc.) marcados con
 * TODO para facilitar el ajuste sin tocar la logica de orquestacion.
 */
final class LibreDteFacturador implements FacturadorInterface
{
    private const URL_PRODUCCION = 'https://libredte.cl';
    private const URL_CERTIFICACION = 'https://libredte.cl'; // mismo host, el ambiente lo controla la cuenta/RUT

    public function __construct(
        private readonly ClientInterface $http = new GuzzleClient(['timeout' => 30]),
    ) {
    }

    public function nombreProveedor(): string
    {
        return 'libredte';
    }

    public function emitir(DocumentoTributario $doc, Credenciales $cred): ResultadoEmision
    {
        $payload = $this->buildEmisionPayload($doc, $cred);
        $response = $this->request('POST', $this->endpointEmision($cred), $cred, $payload);
        $data = $this->decode($response);

        if ($this->respuestaIndicaRechazo($data, $response)) {
            throw new EmisionRechazadaException(
                $this->extraerMensajeError($data) ?? 'Emision rechazada por el proveedor',
                $data,
            );
        }

        return new ResultadoEmision(
            tipoDte: $doc->tipoDte,
            folio:   $this->extraerFolio($data) ?? $doc->folio,
            estado:  (string) ($data['estado'] ?? $data['status'] ?? 'emitido'),
            trackId: isset($data['track_id']) ? (string) $data['track_id'] : null,
            pdfUrl:  isset($data['pdf']) ? (string) $data['pdf'] : null,
            xml:     isset($data['xml']) ? (string) $data['xml'] : null,
            raw:     $data,
        );
    }

    public function consultarEstado(int $folio, int $tipoDte, Credenciales $cred): EstadoDte
    {
        try {
            $tipo = TipoDte::from($tipoDte);
        } catch (ValueError $e) {
            throw new DocumentoInvalidoException(
                "Tipo de DTE no soportado: {$tipoDte}",
                0,
                $e,
            );
        }
        $response = $this->request(
            'GET',
            $this->endpointEstado($cred, $folio, $tipoDte),
            $cred,
        );
        $data = $this->decode($response);

        return new EstadoDte(
            tipoDte: $tipo,
            folio:   $folio,
            estado:  (string) ($data['estado'] ?? $data['status'] ?? 'desconocido'),
            glosa:   isset($data['glosa']) ? (string) $data['glosa'] : null,
            raw:     $data,
        );
    }

    public function anular(
        DocumentoOriginal $original,
        string $motivo,
        TipoAnulacion $tipo,
        Credenciales $cred,
    ): ResultadoEmision {
        // LibreDte: anular() sigue pendiente. Los endpoints de envio asincrono
        // y el shape de la NC contra LibreDte no han sido validados en
        // ambiente real. ApiGateway sí lo implementa.
        //
        // Cuando se implemente aqui, debera reusar la misma logica que
        // ApiGatewayFacturador (replicar receptor y montos del original,
        // armar Referencia con FchRef, distinguir factura vs boleta).
        unset($original, $motivo, $tipo, $cred);

        throw new AnulacionNoSoportadaException(
            'Anulacion no implementada en LibreDteFacturador: pendiente de'
            . ' validacion contra ambiente real. Usar ApiGatewayFacturador para'
            . ' anulacion de facturas.'
        );
    }

    // -----------------------------------------------------------------
    // Region: construccion de payloads y endpoints (PUNTOS SENSIBLES)
    // -----------------------------------------------------------------

    /**
     * TODO: verificar contra doc LibreDTE.
     * Estructura aproximada del JSON de emision (LibreDTE acepta el formato
     * "documento" con Encabezado + Detalle + Referencia).
     *
     * @return array<string,mixed>
     */
    private function buildEmisionPayload(DocumentoTributario $doc, Credenciales $cred): array
    {
        $detalles = [];
        foreach ($doc->detalles as $i => $d) {
            $detalles[] = array_filter([
                'NroLinDet' => $i + 1,
                'NmbItem'   => $d->nombre,
                'DscItem'   => $d->descripcion,
                'QtyItem'   => $d->cantidad,
                'UnmdItem'  => $d->unidad,
                'PrcItem'   => $d->precioUnitario,
                'IndExe'    => $d->exento ? 1 : null,
                'DescuentoPct' => $d->descuentoPorcentaje > 0 ? $d->descuentoPorcentaje : null,
            ], static fn ($v) => $v !== null);
        }

        $referencias = [];
        foreach ($doc->referencias as $i => $r) {
            $referencias[] = array_filter([
                'NroLinRef' => $i + 1,
                'TpoDocRef' => $r['tipoDocumento'] ?? null,
                'FolioRef'  => $r['folio'] ?? null,
                'CodRef'    => $r['codigo'] ?? null,
                'RazonRef'  => $r['razon'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        $encabezado = [
            'IdDoc' => array_filter([
                'TipoDTE' => $doc->tipoDte->value,
                'Folio'   => $doc->folio,
                'FchEmis' => $doc->fechaEmision?->format('Y-m-d'),
                // MntBruto = 1 indica que los PrcItem ya vienen con IVA incluido.
                'MntBruto' => $doc->montosSonBrutos ? 1 : null,
            ], static fn ($v) => $v !== null),
            'Emisor' => [
                'RUTEmisor' => $cred->rutEmisor,
            ],
            'Receptor' => array_filter([
                'RUTRecep'    => $doc->receptor->rut,
                'RznSocRecep' => $doc->receptor->razonSocial,
                'GiroRecep'   => $doc->receptor->giro,
                'DirRecep'    => $doc->receptor->direccion,
                'CmnaRecep'   => $doc->receptor->comuna,
                'CorreoRecep' => $doc->receptor->email,
            ], static fn ($v) => $v !== null),
        ];

        return array_filter([
            'Encabezado' => $encabezado,
            'Detalle'    => $detalles,
            'Referencia' => $referencias !== [] ? $referencias : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * TODO: verificar contra doc LibreDTE el endpoint exacto.
     * Edicion Comunidad usa /api/dte/documento/emitir; SaaS puede diferir.
     */
    private function endpointEmision(Credenciales $cred): string
    {
        return $this->baseUrl($cred) . '/api/dte/documento/emitir';
    }

    /**
     * TODO: verificar contra doc LibreDTE.
     */
    private function endpointEstado(Credenciales $cred, int $folio, int $tipoDte): string
    {
        return sprintf(
            '%s/api/dte/dte_emitidos/info/%d/%d/%s',
            $this->baseUrl($cred),
            $tipoDte,
            $folio,
            rawurlencode($cred->rutEmisor),
        );
    }

    private function baseUrl(Credenciales $cred): string
    {
        if ($cred->baseUrlOverride !== null && trim($cred->baseUrlOverride) !== '') {
            return rtrim($cred->baseUrlOverride, '/');
        }

        return match ($cred->ambiente) {
            Ambiente::Produccion    => self::URL_PRODUCCION,
            Ambiente::Certificacion => self::URL_CERTIFICACION,
        };
    }

    // -----------------------------------------------------------------
    // Region: HTTP / parseo
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $jsonBody
     */
    private function request(string $method, string $uri, Credenciales $cred, ?array $jsonBody = null): ResponseInterface
    {
        // TODO: confirmar en trial - LibreDTE podria usar HTTP Basic
        // (api key como usuario, password vacio) en vez de Bearer.
        $options = [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $cred->apiToken,
            ],
            'http_errors' => false,
        ];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->http->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con LibreDTE: ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta: ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new CredencialesInvalidasException(
                "Credenciales rechazadas por LibreDTE (HTTP $status)"
            );
        }
        if ($status >= 500) {
            throw new ConexionException("LibreDTE respondio HTTP $status");
        }

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new EmisionRechazadaException(
                'Respuesta no es JSON valido',
                ['body' => $body, 'http_status' => $response->getStatusCode()],
            );
        }

        if ($response->getStatusCode() >= 400) {
            throw new EmisionRechazadaException(
                $this->extraerMensajeError($data) ?? "HTTP {$response->getStatusCode()}",
                $data,
                $response->getStatusCode(),
            );
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function respuestaIndicaRechazo(array $data, ResponseInterface $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }
        if (isset($data['error']) && $data['error']) {
            return true;
        }
        if (isset($data['estado']) && in_array(strtolower((string) $data['estado']), ['rechazado', 'error'], true)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extraerMensajeError(array $data): ?string
    {
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        if (isset($data['error']['message']) && is_string($data['error']['message'])) {
            return $data['error']['message'];
        }
        if (isset($data['glosa']) && is_string($data['glosa'])) {
            return $data['glosa'];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extraerFolio(array $data): ?int
    {
        foreach (['folio', 'Folio'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                return (int) $data[$k];
            }
        }
        if (isset($data['Encabezado']['IdDoc']['Folio']) && is_numeric($data['Encabezado']['IdDoc']['Folio'])) {
            return (int) $data['Encabezado']['IdDoc']['Folio'];
        }
        return null;
    }

}
