<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Contracts\FacturadorInterface;
use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\EstadoDte;
use Plantiflex\FacturacionCl\Dto\ResultadoEmision;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\AnulacionNoSoportadaException;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\CuentaNoHabilitadaException;
use Plantiflex\FacturacionCl\Exceptions\EmisionRechazadaException;
use Plantiflex\FacturacionCl\Exceptions\OperacionNoSoportadaException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Implementacion del FacturadorInterface contra la API de API Gateway
 * (apigateway.cl).
 *
 * Caracteristica clave del proveedor: NO administra CAF ni certificado en su
 * backend; somos nosotros quienes:
 *   - Asignamos folio y cargamos el XML del CAF en cada request (FolioRepository).
 *   - Adjuntamos el certificado del emisor (EmisorRepository).
 *
 * Por eso este facturador ORQUESTA tres dependencias:
 *   1. ClientInterface (Guzzle) - transporte HTTP.
 *   2. FolioRepositoryInterface  - folios y CAF.
 *   3. EmisorRepositoryInterface - certificado y datos publicos del emisor.
 *
 * Endpoints confirmados:
 *   - POST {base}/api/v1/libredte/dte/documentos/generar
 *       ?normalizar=1&formato=json&enviar_sii=0
 *     Devuelve {dte, sii, receptor} con XMLs en base64.
 *
 * Endpoints no confirmados (aislados en metodos privados con TODO):
 *   - Envio del DTE generado al SII (paso 2 del flujo).
 *   - Consulta de estado de un DTE.
 *
 * Manejo del folio ante fallo:
 *   Si la emision falla DESPUES de haber asignado el folio, el folio queda
 *   QUEMADO contablemente y se registra en la bitacora con
 *   emision_exitosa=false. No se reutiliza desde esta capa.
 */
final class ApiGatewayFacturador implements FacturadorInterface
{
    private const URL_BASE = 'https://legacy.apigateway.cl';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly FolioRepositoryInterface $folios,
        private readonly EmisorRepositoryInterface $emisor,
    ) {
    }

    public function nombreProveedor(): string
    {
        return 'apigateway';
    }

    public function emitir(DocumentoTributario $doc, Credenciales $cred): ResultadoEmision
    {
        // Reunir DEPENDENCIAS antes de asignar folio.
        //
        // Si algo de esto falla (ej: certificado no cargado para este emisor,
        // datos faltantes, CAF no cargado), la excepcion se propaga SIN haber
        // tocado el contador de folios. Asi un error de configuracion no
        // desperdicia un folio correlativo.
        //
        // Solo despues de tener TODO lo necesario para emitir, se quema el
        // folio. A partir de ese punto, cualquier fallo (generar / enviar al
        // SII) registra el folio con emision_exitosa=false.
        $cafXml = $this->folios->obtenerCafActivo($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);
        $cert   = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos  = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);

        // A partir de aqui el folio queda QUEMADO.
        $folio = $this->folios->asignarSiguienteFolio(
            $cred->rutEmisor,
            $doc->tipoDte,
            $cred->ambiente,
        );

        try {
            $payload = $this->buildPayloadGenerar($doc, $folio, $cert, $datos, $cafXml);

            // Paso 1: generar (enviar_sii=0). Devuelve los 3 XML en base64.
            $generar = $this->postGenerar($payload, $cred);

            // Paso 2: enviar al SII (endpoint no confirmado, aislado).
            $envio = $this->enviarAlSii($generar, $cred);

            $this->folios->marcarFolioComoUsado(
                $cred->rutEmisor,
                $doc->tipoDte,
                $cred->ambiente,
                $folio,
                true,
            );

            return new ResultadoEmision(
                tipoDte: $doc->tipoDte,
                folio:   $folio,
                estado:  (string) ($envio['estado'] ?? 'enviado'),
                trackId: isset($envio['track_id']) ? (string) $envio['track_id'] : null,
                pdfUrl:  null,
                xml:     $this->decodeBase64Safe($generar['dte'] ?? null),
                raw:     ['generar' => $generar, 'envio' => $envio],
            );
        } catch (Throwable $e) {
            // Fallo en generar/enviar -> el folio ya estaba asignado: marcar
            // exito=false (queda quemado en bitacora) y relanzar.
            $this->folios->marcarFolioComoUsado(
                $cred->rutEmisor,
                $doc->tipoDte,
                $cred->ambiente,
                $folio,
                false,
            );
            throw $e;
        }
    }

    public function consultarEstado(int $folio, int $tipoDte, Credenciales $cred): EstadoDte
    {
        // TODO: confirmar endpoint de consulta de estado en API Gateway.
        // La doc menciona una pagina "Consultar estado DTE" pero la ruta y el
        // formato de respuesta no estan validados en ambiente real.
        unset($folio, $tipoDte, $cred);
        throw new OperacionNoSoportadaException(
            'Consulta de estado en API Gateway: endpoint pendiente de validacion en ambiente real.'
        );
    }

    public function anular(
        DocumentoOriginal $original,
        string $motivo,
        TipoAnulacion $tipo,
        Credenciales $cred,
    ): ResultadoEmision {
        // 1. Por ahora solo anulacion total.
        if ($tipo !== TipoAnulacion::AnulaTotal) {
            throw new OperacionNoSoportadaException(
                'Solo TipoAnulacion::AnulaTotal esta implementado.'
                . ' CorrigeMonto y CorrigeTexto pendientes de implementacion.'
            );
        }

        // 2. Boletas (39/41): el flujo de anulacion es distinto (RCV / anulacion
        //    en el portal del SII) y no esta confirmado contra el ambiente real.
        if ($original->tipoDte->esBoleta()) {
            throw new AnulacionNoSoportadaException(
                'Anulacion de boletas (39/41) no implementada: el flujo difiere'
                . ' del de facturas y no esta confirmado contra el SII. Pendiente'
                . ' de validacion en ambiente real.'
            );
        }

        // 3. Facturas (33/34): construir la NC replicando el documento original.
        //    La NC necesita su PROPIO folio (de la serie 61), por eso reusamos
        //    emitir() en vez de armar un payload aparte: asigna folio de NC,
        //    obtiene CAF de NC, certificado, datos del emisor, emite y envia.
        $nc = new DocumentoTributario(
            tipoDte:         TipoDte::NotaCreditoElectronica,
            receptor:        $original->receptor,     // receptor REAL del original
            detalles:        $original->detalles,     // mismos montos que el original
            montosSonBrutos: $original->montosSonBrutos,
            referencias:     [[
                'tipoDocumento' => $original->tipoDte->value,
                'folio'         => $original->folio,
                'codigo'        => TipoAnulacion::AnulaTotal->value, // CodRef=1
                'razon'         => $motivo,
                'fecha'         => $original->fechaEmision->format('Y-m-d'),
            ]],
            observaciones:   $motivo,
            // TODO: confirmar en ambiente real si normalizar=1 recalcula los
            // totales o respeta los enviados para una NC. Por ahora pasamos los
            // del original para anular el monto exacto del documento referido.
            totales:         [
                'MntNeto'  => $original->montoNeto,
                'IVA'      => $original->iva,
                'MntTotal' => $original->montoTotal,
            ],
        );

        return $this->emitir($nc, $cred);
    }

    // ------------------------------------------------------------------
    // Region: construccion del payload (PUNTO SENSIBLE - puede cambiar)
    // ------------------------------------------------------------------

    /**
     * Arma los 4 bloques obligatorios del request /generar:
     *   - auth        certificado y llave privada del emisor
     *   - dte         Encabezado + Detalle + (opcional) Referencia
     *   - resolucion  fecha y numero de la resolucion SII del emisor
     *   - caf         XML del CAF en base64
     *
     * Diferencias boleta (39) vs factura (33) en el bloque Emisor:
     *   - Boleta: RznSocEmisor + GiroEmisor
     *   - Factura: RznSoc + GiroEmis
     * Esto sigue el esquema XSD del SII.
     *
     * @return array<string,mixed>
     */
    private function buildPayloadGenerar(
        DocumentoTributario $doc,
        int $folio,
        Certificado $cert,
        DatosEmisor $datos,
        string $cafXml,
    ): array {
        $idDoc = array_filter([
            'TipoDTE'  => $doc->tipoDte->value,
            'Folio'    => $folio,
            'FchEmis'  => $doc->fechaEmision?->format('Y-m-d') ?? date('Y-m-d'),
            // MntBruto=1 indica que PrcItem viene con IVA incluido. Tipico de
            // boletas (39); facturas (33) suelen viajar netas.
            'MntBruto' => $doc->montosSonBrutos ? 1 : null,
        ], static fn ($v) => $v !== null);

        $emisor = [
            'RUTEmisor' => $datos->rutEmisor,
        ];
        // Nombres de campos segun esquema SII para boleta vs factura.
        // TODO: verificar en ambiente real los nombres exactos de campos del
        // Emisor para boleta (RznSocEmisor/GiroEmisor) vs factura
        // (RznSoc/GiroEmis). El SII es estricto con esto y la doc consultada
        // era parcial: un nombre incorrecto causa rechazo del DTE.
        if ($doc->tipoDte->esBoleta()) {
            $emisor['RznSocEmisor'] = $datos->razonSocial;
            $emisor['GiroEmisor']   = $datos->giro;
        } else {
            $emisor['RznSoc']  = $datos->razonSocial;
            $emisor['GiroEmis'] = $datos->giro;
        }
        $emisor['Acteco']     = $datos->acteco;
        $emisor['DirOrigen']  = $datos->dirOrigen;
        $emisor['CmnaOrigen'] = $datos->cmnaOrigen;

        $receptor = array_filter([
            'RUTRecep'    => $doc->receptor->rut,
            'RznSocRecep' => $doc->receptor->razonSocial,
            'GiroRecep'   => $doc->receptor->giro,
            'DirRecep'    => $doc->receptor->direccion,
            'CmnaRecep'   => $doc->receptor->comuna,
            'CorreoRecep' => $doc->receptor->email,
        ], static fn ($v) => $v !== null && $v !== '');

        $detalle = [];
        foreach ($doc->detalles as $i => $d) {
            $detalle[] = array_filter([
                'NroLinDet'    => $i + 1,
                'NmbItem'      => $d->nombre,
                'DscItem'      => $d->descripcion,
                'QtyItem'      => $d->cantidad,
                'UnmdItem'     => $d->unidad,
                'PrcItem'      => $d->precioUnitario,
                'IndExe'       => $d->exento ? 1 : null,
                'DescuentoPct' => $d->descuentoPorcentaje > 0 ? $d->descuentoPorcentaje : null,
            ], static fn ($v) => $v !== null);
        }

        $referencia = [];
        foreach ($doc->referencias as $i => $r) {
            $referencia[] = array_filter([
                'NroLinRef' => $i + 1,
                'TpoDocRef' => $r['tipoDocumento'] ?? null,
                'FolioRef'  => $r['folio'] ?? null,
                'FchRef'    => $r['fecha'] ?? null,
                'CodRef'    => $r['codigo'] ?? null,
                'RazonRef'  => $r['razon'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        $encabezado = [
            'IdDoc'    => $idDoc,
            'Emisor'   => $emisor,
            'Receptor' => $receptor,
        ];
        if ($doc->totales !== null && $doc->totales !== []) {
            // Totales explicitos (tipico de NC que anula: necesitamos que el
            // monto refleje exacto al original, sin que normalizar=1 recalcule).
            $encabezado['Totales'] = $doc->totales;
        }

        $dte = [
            'Encabezado' => $encabezado,
            'Detalle'    => $detalle,
        ];
        if ($referencia !== []) {
            $dte['Referencia'] = $referencia;
        }

        return [
            'auth'       => $cert->toAuthArray(),
            'dte'        => $dte,
            'resolucion' => [
                'fecha'  => $datos->resolucionFecha,
                'numero' => $datos->resolucionNumero,
            ],
            'caf' => base64_encode($cafXml),
        ];
    }

    // ------------------------------------------------------------------
    // Region: HTTP / endpoints
    // ------------------------------------------------------------------

    /**
     * POST al endpoint /generar. Devuelve la respuesta JSON decodificada,
     * que debe contener al menos las claves dte, sii y receptor (XMLs base64).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postGenerar(array $payload, Credenciales $cred): array
    {
        $url = $this->baseUrl($cred)
            . '/api/v1/libredte/dte/documentos/generar'
            . '?normalizar=1&formato=json&enviar_sii=0';

        $response = $this->request('POST', $url, $cred, $payload);
        $data = $this->decode($response);

        foreach (['dte', 'sii', 'receptor'] as $clave) {
            if (! isset($data[$clave])) {
                throw new EmisionRechazadaException(
                    sprintf('Respuesta de /generar incompleta: falta "%s"', $clave),
                    $data,
                );
            }
        }
        return $data;
    }

    /**
     * Paso 2 del flujo: enviar el XML generado al SII.
     *
     * TODO: confirmar endpoint de envio al SII en ambiente real. La doc
     * menciona "Enviar XML al SII" pero ruta exacta, shape del body y
     * respuesta no estan validados. Si la ruta cambia, ajustar solo aqui.
     *
     * @param array<string,mixed> $generar Respuesta de /generar (con dte/sii/receptor base64).
     * @return array<string,mixed>
     */
    private function enviarAlSii(array $generar, Credenciales $cred): array
    {
        $url = $this->baseUrl($cred) . '/api/v1/libredte/dte/documentos/enviar_sii';
        // TODO: confirmar en ambiente real ruta + shape del body.
        //
        // Usamos $generar['sii'] (no $generar['dte']): la respuesta de /generar
        // trae dos XML distintos:
        //   - 'dte': XML del DTE individual (para PDF, archivo del emisor).
        //   - 'sii': XML "EnvioDTE" listo para ser enviado al SII (es el sobre
        //           con la firma del set, no del documento). ESE es el que va.
        $payload = ['xml' => $generar['sii']];

        $response = $this->request('POST', $url, $cred, $payload);
        return $this->decode($response);
    }

    private function baseUrl(Credenciales $cred): string
    {
        if ($cred->baseUrlOverride !== null && trim($cred->baseUrlOverride) !== '') {
            return rtrim($cred->baseUrlOverride, '/');
        }
        return self::URL_BASE;
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     */
    private function request(string $method, string $uri, Credenciales $cred, ?array $jsonBody = null): ResponseInterface
    {
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                // API Gateway usa el esquema "Token <token>", no Bearer ni Basic.
                'Authorization' => 'Token ' . $cred->apiToken,
            ],
            'http_errors' => false,
        ];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->http->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con API Gateway: ' . $e->getMessage(), 0, $e);
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
        // 402 = cuenta no habilitada / sin creditos. La auth fue valida.
        if ($status === 402) {
            throw new CuentaNoHabilitadaException(
                'API Gateway respondio HTTP 402: cuenta no habilitada o sin creditos.'
            );
        }
        if ($status === 401 || $status === 403) {
            throw new CredencialesInvalidasException(
                "Credenciales rechazadas por API Gateway (HTTP $status)"
            );
        }
        if ($status >= 500) {
            throw new ConexionException("API Gateway respondio HTTP $status");
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
                (string) ($data['message'] ?? $data['error'] ?? 'HTTP ' . $response->getStatusCode()),
                $data,
                $response->getStatusCode(),
            );
        }
        return $data;
    }

    private function decodeBase64Safe(mixed $v): ?string
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        $r = base64_decode($v, true);
        return $r === false ? null : $r;
    }
}
