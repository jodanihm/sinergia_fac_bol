<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Correo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Cliente de la API HTTP de correo transaccional de Brevo.
 *
 * POST https://api.brevo.com/v3/smtp/email, autenticado con la cabecera
 * 'api-key'. Se usa la API HTTP y NO SMTP a proposito: guzzlehttp/guzzle ya es
 * dependencia del proyecto (la usa el motor para hablar con el SII), asi que no
 * hay que agregar nada al composer.json ni escribir MIME multiparte a mano --
 * los adjuntos viajan como base64 dentro del JSON.
 *
 * POR QUE FALLA RUIDOSO SI FALTA LA CONFIGURACION. Sigue el criterio de
 * panel/src/Db.php (requerirEnv lanza si la variable falta o esta vacia) y NO el
 * criterio laxo del motor (getenv(...) ?: 'valor por defecto'). Una API key
 * ausente que degrade en silencio no produce "no se envio": produce un intento
 * de envio contra una cuenta equivocada, o un 401 tragado que nadie mira. En un
 * modulo que manda documentos tributarios a terceros, eso no es aceptable.
 *
 * NO CONOCE dte_envio_correo NI LA BASE. Recibe todo armado. Asi se puede probar
 * sin base de datos y reusar para cualquier otro correo del sistema.
 */
final class BrevoMailer
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /**
     * Techo por adjunto, en bytes.
     *
     * 4 MB es el mas conservador de los dos limites que publica Brevo: la
     * referencia de la API habla de 20 MB para el correo completo, y el centro
     * de ayuda de 4 MB por adjunto con 5 MB de correo total. Ante dos numeros
     * oficiales que no coinciden, se disena contra el chico.
     *
     * Referencia: un EnvioDTE ronda los 27 KB y un PDF generado los 129 KB, o
     * sea que el uso normal esta dos ordenes de magnitud por debajo. Este limite
     * existe para que un XML anomalo (un sobre de lote enorme) falle aqui con un
     * mensaje claro y no con un 413 opaco de la API.
     */
    public const MAX_ADJUNTO_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $remitenteEmail,
        private readonly string $remitenteNombrePorDefecto,
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('BREVO_API_KEY vacia: no se envia nada.');
        }
        if (trim($this->remitenteEmail) === '') {
            throw new RuntimeException('CORREO_REMITENTE vacio: no se envia nada.');
        }
    }

    /**
     * Construye el cliente desde el entorno. Lanza si falta cualquiera de las
     * tres variables (ver .env.example).
     */
    public static function desdeEntorno(?Client $http = null): self
    {
        return new self(
            $http ?? new Client(['timeout' => 30, 'http_errors' => false]),
            self::requerirEnv('BREVO_API_KEY'),
            self::requerirEnv('CORREO_REMITENTE'),
            self::requerirEnv('CORREO_REMITENTE_NOMBRE'),
        );
    }

    private static function requerirEnv(string $nombre): string
    {
        $valor = getenv($nombre);
        if ($valor === false || trim($valor) === '') {
            throw new RuntimeException("config de correo incompleta: falta {$nombre}");
        }

        return $valor;
    }

    /**
     * Envia un correo con adjuntos.
     *
     * @param list<array{nombre:string, contenido:string}> $adjuntos contenido en
     *        BYTES CRUDOS; el base64 lo hace este metodo. Pasarlo ya codificado
     *        lo codificaria dos veces.
     *
     * @return array{status:int, body:string} la respuesta cruda de la API. Un
     *         2xx es exito; el llamador decide que hacer con el resto. No se
     *         lanza por un 4xx/5xx (http_errors=false): un rechazo de Brevo es
     *         un dato, no una excepcion.
     *
     * @throws RuntimeException si un adjunto supera el techo, o si la conexion
     *         falla (no hay respuesta HTTP que interpretar).
     */
    public function enviar(
        string $destinatarioEmail,
        string $asunto,
        string $htmlCuerpo,
        array $adjuntos = [],
        ?string $replyToEmail = null,
        ?string $remitenteNombre = null,
        ?string $destinatarioNombre = null,
    ): array {
        $payload = [
            'sender' => [
                'email' => $this->remitenteEmail,
                // Brevo corta el nombre del remitente en 70 caracteres.
                'name'  => mb_substr($remitenteNombre ?? $this->remitenteNombrePorDefecto, 0, 70),
            ],
            'to'          => [array_filter([
                'email' => $destinatarioEmail,
                'name'  => $destinatarioNombre,
            ], static fn ($v): bool => $v !== null && $v !== '')],
            'subject'     => $asunto,
            'htmlContent' => $htmlCuerpo,
        ];

        // Reply-To OPCIONAL: si el tenant no tiene correo utilizable, el mensaje
        // sale igual y las respuestas caen en el remitente del sistema. Vale mas
        // entregar el documento que no entregarlo por un dato de contacto.
        if ($replyToEmail !== null && trim($replyToEmail) !== '') {
            $payload['replyTo'] = ['email' => $replyToEmail];
        }

        if ($adjuntos !== []) {
            $payload['attachment'] = array_map(
                function (array $a): array {
                    $bytes = $a['contenido'];
                    if (strlen($bytes) > self::MAX_ADJUNTO_BYTES) {
                        throw new RuntimeException(sprintf(
                            'el adjunto %s pesa %d bytes y el maximo es %d',
                            $a['nombre'],
                            strlen($bytes),
                            self::MAX_ADJUNTO_BYTES
                        ));
                    }

                    // base64 DIRECTO sobre los bytes crudos. Ver el aviso de
                    // scripts/enviar_correo.php: el XML del DTE va firmado y en
                    // ISO-8859-1, y cualquier transcodificacion previa
                    // invalidaria la firma ante el SII.
                    return ['name' => $a['nombre'], 'content' => base64_encode($bytes)];
                },
                $adjuntos
            );
        }

        try {
            $resp = $this->http->post(self::ENDPOINT, [
                'headers' => [
                    'api-key'      => $this->apiKey,
                    'accept'       => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('no se pudo contactar la API de Brevo: ' . $e->getMessage(), 0, $e);
        }

        return ['status' => $resp->getStatusCode(), 'body' => (string) $resp->getBody()];
    }
}
