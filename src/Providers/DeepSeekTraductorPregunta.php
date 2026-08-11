<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Plantiflex\FacturacionCl\Contracts\TraductorPreguntaInterface;
use Plantiflex\FacturacionCl\Dto\PreguntaTraducida;
use Plantiflex\FacturacionCl\Dto\VocabularioConsulta;
use Plantiflex\FacturacionCl\Exceptions\TraduccionPreguntaException;

/**
 * Traduccion de preguntas a perillas contra DeepSeek.
 *
 * POST https://api.deepseek.com/chat/completions
 * Cabecera: Authorization: Bearer <clave>   -- aqui SI es "Bearer", a diferencia
 * de ApiGatewayContribuyente, que usa el prefijo literal "Token". Confundirlos
 * da 401 en los dos sentidos.
 *
 * La API de DeepSeek es compatible con la forma de OpenAI: se manda un arreglo
 * de mensajes y la respuesta viene en choices[0].message.content.
 *
 *
 * EL MOLDE ES ApiGatewayContribuyente, Y SE SIGUE EN LOS CUATRO PUNTOS
 * -----------------------------------------------------------------------------
 *   - desdeEntorno(?Client $http = null): produccion la llama sin argumentos; un
 *     test le inyecta un Guzzle con MockHandler. Es lo que hace probable esta
 *     clase sin gastar una sola consulta real.
 *   - http_errors = false: un 401 o un 429 del proveedor es UN DATO, no una
 *     excepcion de transporte. Se interpretan aqui.
 *   - Solo el fallo de CONEXION se vuelve excepcion, porque ahi no hay ninguna
 *     respuesta que interpretar.
 *   - NO LANZA SI FALTA LA CLAVE al construir. El fallo aparece al traducir,
 *     como TraduccionPreguntaException::sinClave(), que la pantalla sabe
 *     convertir en un mensaje util. Un chat caido no puede impedir entrar al
 *     panel.
 *
 *
 * AL MODELO SOLO VIAJA LA PREGUNTA DEL USUARIO
 * -----------------------------------------------------------------------------
 * Ni cuenta_id, ni RUT del emisor, ni un total, ni una fila. La firma de
 * traducir() ni siquiera admite esos datos, que es la forma mas barata de
 * garantizarlo: no se puede filtrar lo que no se recibe.
 *
 * LIMITE CONOCIDO Y NO RESUELTO: si el usuario ESCRIBE el nombre de un cliente
 * en su pregunta ("cuanto le vendi a Comercial Perez"), ese nombre viaja dentro
 * de la pregunta. No se filtra ni se ofusca en esta entrega. Queda anotado.
 *
 *
 * CADA CONSULTA CUESTA
 * -----------------------------------------------------------------------------
 * Esta clase NO cachea ni reintenta: una peticion por llamada, que es lo
 * correcto para una pieza de transporte. Mismo criterio que
 * ApiGatewayContribuyente.
 */
final class DeepSeekTraductorPregunta implements TraductorPreguntaInterface
{
    public const BASE_URL = 'https://api.deepseek.com/';

    /**
     * Nombre de la variable de entorno, en un solo lugar.
     *
     * VIVE EN env/sinergia.env, que es el UNICO archivo de entorno del proyecto:
     * lo cargan sinergia_motor y sinergia_panel por el mismo env_file. No hay un
     * archivo por servicio, asi que ponerla en cualquier otro sitio equivale a
     * no ponerla -- nadie lo leeria y el chat diria que no esta configurado.
     */
    public const ENV_CLAVE = 'DEEPSEEK_API_KEY';

    /**
     * 30 segundos, no los 45 de ApiGatewayContribuyente. Aquel espera al SII, que
     * puede tardar 20; aqui el unico que responde es el modelo, y una traduccion
     * de una frase corta no deberia pasar de unos segundos. Un chat que se queda
     * pensando medio minuto ya perdio.
     */
    public const TIMEOUT_SEGUNDOS = 30;

    /** El barato. El trabajo es clasificar una frase, no razonar. */
    public const MODELO = 'deepseek-chat';

    /**
     * Temperatura 0: se quiere la MISMA traduccion para la misma pregunta. Esto
     * no es redaccion, es clasificacion; la creatividad aqui es un defecto.
     */
    public const TEMPERATURA = 0.0;

    public function __construct(
        private readonly Client $http,
        private readonly string $clave,
    ) {
    }

    public static function desdeEntorno(?Client $http = null): self
    {
        $clave = getenv(self::ENV_CLAVE);

        return new self(
            $http ?? new Client([
                'base_uri'    => self::BASE_URL,
                'timeout'     => self::TIMEOUT_SEGUNDOS,
                'http_errors' => false,
            ]),
            $clave === false ? '' : trim($clave),
        );
    }

    public function traducir(string $pregunta, VocabularioConsulta $vocabulario, string $hoy): PreguntaTraducida
    {
        if ($this->clave === '') {
            throw TraduccionPreguntaException::sinClave(self::ENV_CLAVE);
        }

        $cuerpo = [
            'model'    => self::MODELO,
            'messages' => [
                ['role' => 'system', 'content' => $this->instrucciones($vocabulario, $hoy)],
                // LA PREGUNTA VA SOLA Y SIN ADORNOS. Nada del tenant se concatena
                // aqui: lo unico que sale de este proceso es lo que el usuario
                // escribio.
                ['role' => 'user', 'content' => $pregunta],
            ],
            'temperature' => self::TEMPERATURA,
            // MODO JSON DE DEEPSEEK. Obliga al modelo a devolver un objeto JSON
            // valido, que es lo que evita tener que rescatar el JSON de entre un
            // parrafo de cortesia. Igual se parsea a la defensiva: "JSON valido"
            // no significa "con las claves que esperamos".
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $respuesta = $this->http->request('POST', 'chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->clave,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $cuerpo,
            ]);
        } catch (GuzzleException $e) {
            // Solo el fallo de CONEXION llega aqui: con http_errors=false, un
            // 4xx/5xx es una respuesta y se interpreta abajo.
            throw TraduccionPreguntaException::sinRespuesta($e->getMessage(), $e);
        }

        $status = $respuesta->getStatusCode();
        $texto  = (string) $respuesta->getBody();

        if ($status === 401 || $status === 403) {
            throw TraduccionPreguntaException::sinClave(self::ENV_CLAVE);
        }
        if ($status !== 200) {
            throw TraduccionPreguntaException::sinRespuesta("HTTP {$status}");
        }

        return $this->interpretar($texto);
    }

    /**
     * Saca las perillas del JSON del proveedor.
     *
     * A LA DEFENSIVA EN LOS DOS NIVELES: el sobre de DeepSeek y el JSON que el
     * modelo escribio dentro. Los dos pueden venir mal, y por motivos distintos.
     */
    private function interpretar(string $texto): PreguntaTraducida
    {
        $sobre = json_decode($texto, true);
        if (! is_array($sobre)) {
            throw TraduccionPreguntaException::respuestaIlegible('el cuerpo no es JSON');
        }
        $contenido = $sobre['choices'][0]['message']['content'] ?? null;
        if (! is_string($contenido) || trim($contenido) === '') {
            throw TraduccionPreguntaException::respuestaIlegible('no vino choices[0].message.content');
        }

        $dato = json_decode($contenido, true);
        if (! is_array($dato)) {
            throw TraduccionPreguntaException::respuestaIlegible(
                'lo que escribio el modelo no es JSON: ' . mb_substr(trim($contenido), 0, 120)
            );
        }

        $desenlace = (string) ($dato['desenlace'] ?? '');

        if ($desenlace === PreguntaTraducida::IMPOSIBLE) {
            return PreguntaTraducida::imposible($this->motivoDe($dato,
                'esa pregunta no se puede responder con los datos disponibles'));
        }
        if ($desenlace === PreguntaTraducida::NO_ENTENDIDA) {
            return PreguntaTraducida::noEntendida($this->motivoDe($dato,
                'no entendi la pregunta; prueba a decirla de otra forma'));
        }
        if ($desenlace !== PreguntaTraducida::PERILLAS) {
            throw TraduccionPreguntaException::respuestaIlegible(
                "desenlace desconocido: '" . mb_substr($desenlace, 0, 40) . "'"
            );
        }

        $perillas = $dato['perillas'] ?? null;
        if (! is_array($perillas)) {
            throw TraduccionPreguntaException::respuestaIlegible('el desenlace es perillas pero no vinieron');
        }

        // SE DEVUELVEN TAL CUAL, SIN VALIDAR. Validar es del repositorio: si esta
        // clase filtrara o corrigiera, habria dos validadores y el resultado
        // dependeria de cual dejo pasar que. Una metrica alucinada tiene que dar
        // el mismo rechazo que un POST malformado.
        //
        // Lo unico que se hace es normalizar el TIPO de limite: el modo JSON lo
        // puede devolver como "20" y el validador exige entero o digitos.
        if (isset($perillas['limite']) && is_string($perillas['limite']) && ctype_digit($perillas['limite'])) {
            $perillas['limite'] = (int) $perillas['limite'];
        }

        return PreguntaTraducida::conPerillas($perillas);
    }

    /** @param array<string,mixed> $dato */
    private function motivoDe(array $dato, string $porDefecto): string
    {
        $m = trim((string) ($dato['motivo'] ?? ''));

        return $m !== '' ? $m : $porDefecto;
    }

    /**
     * El prompt de sistema.
     *
     * LAS OPCIONES SALEN DEL VOCABULARIO, NO ESTAN ESCRITAS AQUI. Si estuvieran,
     * agregar una metrica al repositorio dejaria al modelo ofreciendo la lista
     * vieja -- proponiendo algo que el validador despues rechaza, que es la peor
     * de las dos formas de desincronizarse.
     *
     * Y SE LE DA PERMISO EXPLICITO PARA NO SABER. Un modelo al que solo se le
     * ofrece un formato de respuesta valido rellena los huecos con lo que sea; el
     * "imposible" y el "no_entendida" existen para que tenga a donde ir cuando la
     * respuesta honesta es que no.
     */
    private function instrucciones(VocabularioConsulta $vocabulario, string $hoy): string
    {
        return <<<TXT
            Eres un traductor. Conviertes una pregunta sobre facturacion en un objeto JSON
            con los parametros de una consulta. NO respondes la pregunta, NO calculas nada y
            NO inventas cifras: solo dices que habria que consultar.

            La fecha de hoy es {$hoy}. Usala para resolver expresiones relativas como
            "el mes pasado", "este año" o "los ultimos 6 meses", convirtiendolas a fechas
            concretas.

            OPCIONES VALIDAS (no uses ninguna otra):
            {$vocabulario->comoTexto()}

            Responde SIEMPRE un unico objeto JSON, sin texto alrededor, con una de estas
            tres formas:

            1) Si la pregunta se puede traducir:
            {"desenlace":"perillas","perillas":{"metrica":"...","agruparPor":"...","desde":"AAAA-MM-DD","hasta":"AAAA-MM-DD","orden":"...","limite":10}}

            2) Si la pregunta se entiende pero NO se puede responder con estos datos
               (por ejemplo cualquier cosa a nivel de producto):
            {"desenlace":"imposible","motivo":"explicacion breve y en español para el usuario"}

            3) Si la pregunta no es sobre facturacion, o no la entiendes:
            {"desenlace":"no_entendida","motivo":"explicacion breve y en español para el usuario"}

            EJEMPLOS DE QUE ELEGIR (las opciones salen de la lista de arriba, estos
            ejemplos solo muestran COMO se usan):
            - "detalle de facturacion de agosto", "muestrame los documentos de agosto",
              "listado de facturas del mes" -> agruparPor "documento": el usuario quiere
              ver los documentos uno por uno, no un total.
            - "cuanto vendi en agosto", "cual fue mi facturacion del año" -> agruparPor
              "ninguna": quiere una cifra del periodo. La respuesta siempre incluye neto,
              exento, impuestos y cantidad de documentos, asi que no hace falta que elijas
              entre ellos: usa "monto" salvo que pregunte por uno en concreto.
            - "mi mejor cliente", "a quien le vendi mas" -> agruparPor "cliente".
            - "que mes vendi mas", "como me fue mes a mes" -> agruparPor "mes".

            REGLAS:
            - Prefiere decir "imposible" o "no_entendida" antes que adivinar. Una respuesta
              inventada es peor que un "no se puede".
            - Con agruparPor "documento", pon un limite acorde a lo que se pidio: un mes
              cabe en 100, "todo el año" no cabe en ninguna pantalla. Si dudas, usa 50.
            - No agregues claves que no esten en la lista de opciones validas: se rechazan.
            - Si la pregunta no indica periodo, usa el año en curso hasta {$hoy}.
            - Si no pide agrupacion, usa "ninguna". Si no pide orden, usa "metrica_desc".
            - El motivo se le muestra tal cual al usuario: escribelo claro y sin jerga
              tecnica, y sin mencionar nombres de columnas ni de tablas.
            TXT;
    }
}
