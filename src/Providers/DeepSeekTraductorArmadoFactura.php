<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Plantiflex\FacturacionCl\Contracts\TraductorArmadoFacturaInterface;
use Plantiflex\FacturacionCl\Dto\ArmadoFacturaTraducido;
use Plantiflex\FacturacionCl\Dto\VocabularioArmadoFactura;
use Plantiflex\FacturacionCl\Exceptions\TraduccionArmadoException;

/**
 * Armado de facturas por conversacion contra DeepSeek.
 *
 * HERMANO DE DeepSeekTraductorPregunta, NO SU SUSTITUTO. Aquella clase NO SE TOCA
 * en esta entrega: su interprete es lista cerrada y agregarle desenlaces romperia
 * el chat de consultas, que ya corre en produccion.
 *
 * Sigue los mismos cuatro puntos del molde ApiGatewayContribuyente:
 *   - desdeEntorno(?Client $http = null): produccion sin argumentos, un test le
 *     inyecta un Guzzle con MockHandler.
 *   - http_errors = false: un 401 o un 429 es UN DATO, no una excepcion.
 *   - Solo el fallo de CONEXION se vuelve excepcion.
 *   - NO LANZA SI FALTA LA CLAVE al construir; falla al traducir.
 *
 *
 * LA MISMA CREDENCIAL QUE EL CHAT DE CONSULTAS, Y UNA SOLA CONSTANTE
 * -----------------------------------------------------------------------------
 * Es el mismo proveedor y la misma clave. Declarar aqui otra constante con el
 * mismo texto crearia dos nombres que hay que mantener iguales a mano; el dia que
 * uno cambie, el chat diria "no configurado" por un motivo invisible. Se referencia
 * la del hermano, que es la unica definicion.
 *
 *
 * AL MODELO SOLO VIAJAN LAS FRASES DEL USUARIO Y EL BORRADOR QUE EL MISMO ESCRIBIO
 * -----------------------------------------------------------------------------
 * Ver la nota larga de TraductorArmadoFacturaInterface. La firma no admite
 * cuenta_id, ni una fila del maestro, ni un total.
 *
 *
 * CADA TURNO CUESTA
 * -----------------------------------------------------------------------------
 * Una peticion por llamada, sin cache ni reintento -- lo correcto para una pieza
 * de transporte. Y aqui pesa mas que en las consultas: una factura conversada son
 * varios turnos, o sea varias llamadas. Quien llame tiene que mirar el cupo ANTES,
 * igual que ya hace el chat de consultas.
 */
final class DeepSeekTraductorArmadoFactura implements TraductorArmadoFacturaInterface
{
    public const BASE_URL = 'https://api.deepseek.com/';

    /** La misma variable de entorno que el chat de consultas; una sola definicion. */
    public const ENV_CLAVE = DeepSeekTraductorPregunta::ENV_CLAVE;

    /**
     * 45 segundos, no los 30 del traductor de consultas.
     *
     * Aquel clasifica una frase corta; esto tiene que leer el hilo entero de la
     * conversacion, decidir cuantos documentos salen y devolver un objeto con
     * lineas. Es mas trabajo y mas tokens de salida. Sigue siendo un tope de
     * paciencia, no una estimacion: si tarda mas, algo va mal y conviene fallar.
     */
    public const TIMEOUT_SEGUNDOS = 45;

    /** El mismo modelo barato: sigue siendo extraer datos de una frase. */
    public const MODELO = 'deepseek-chat';

    /**
     * Temperatura 0, y aqui importa mas que en las consultas: el usuario va a
     * corregir sobre lo que el modelo entendio, y si la misma frase produjera
     * borradores distintos, corregir seria perseguir un blanco movil.
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

    public function traducir(
        array $turnosUsuario,
        array $borradorPrevio,
        VocabularioArmadoFactura $vocabulario,
        string $hoy,
        array $avisosDelPanel = [],
    ): ArmadoFacturaTraducido {
        if ($this->clave === '') {
            throw TraduccionArmadoException::sinClave(self::ENV_CLAVE);
        }

        // UN TURNO VACIO NO SALE. No es un caso raro: el formulario puede
        // reenviarse sin texto. Gastar una llamada para que el modelo conteste
        // "no entendi" seria pagar por nada.
        $turnosUsuario = array_values(array_filter(
            array_map(static fn ($t): string => trim((string) $t), $turnosUsuario),
            static fn (string $t): bool => $t !== '',
        ));
        if ($turnosUsuario === []) {
            return ArmadoFacturaTraducido::noEntendida('no escribiste nada todavia.');
        }

        // ¿HAY ALGO EN CURSO? De esto depende que el desenlace "cambio_de_tema"
        // exista o no. Ver el docblock de instrucciones() y el de interpretar().
        $hayBorradorPrevio = $borradorPrevio !== [];

        $mensajes = [[
            'role'    => 'system',
            'content' => $this->instrucciones($vocabulario, $hoy, $hayBorradorPrevio, $avisosDelPanel),
        ]];

        // EL BORRADOR PREVIO VA COMO MENSAJE DEL ASISTENTE, que es lo que fue: lo
        // escribio el modelo. Ponerlo como mensaje del usuario le diria que la
        // persona tecleo un JSON, y a la vuelta siguiente empezaria a contestarle
        // en ese registro.
        if ($borradorPrevio !== []) {
            $mensajes[] = [
                'role'    => 'assistant',
                'content' => json_encode(
                    ['desenlace' => ArmadoFacturaTraducido::FALTAN_DATOS, 'borrador' => $borradorPrevio],
                    JSON_UNESCAPED_UNICODE
                ),
            ];
        }

        // LAS FRASES DEL USUARIO, UNA POR MENSAJE Y SIN ADORNOS. Nada del tenant
        // se concatena aqui: lo unico que sale de este proceso es lo que la
        // persona escribio.
        foreach ($turnosUsuario as $t) {
            $mensajes[] = ['role' => 'user', 'content' => $t];
        }

        try {
            $respuesta = $this->http->request('POST', 'chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->clave,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => [
                    'model'           => self::MODELO,
                    'messages'        => $mensajes,
                    'temperature'     => self::TEMPERATURA,
                    'response_format' => ['type' => 'json_object'],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw TraduccionArmadoException::sinRespuesta($e->getMessage(), $e);
        }

        $status = $respuesta->getStatusCode();
        $texto  = (string) $respuesta->getBody();

        if ($status === 401 || $status === 403) {
            throw TraduccionArmadoException::sinClave(self::ENV_CLAVE);
        }
        if ($status !== 200) {
            throw TraduccionArmadoException::sinRespuesta("HTTP {$status}");
        }

        return $this->interpretar($texto, $hayBorradorPrevio);
    }

    /**
     * Saca el desenlace del JSON del proveedor.
     *
     * A LA DEFENSIVA EN LOS DOS NIVELES -- el sobre de DeepSeek y el JSON que el
     * modelo escribio dentro --, igual que el hermano. Los dos pueden venir mal, y
     * por motivos distintos.
     *
     * UN DESENLACE DESCONOCIDO ES UN ERROR, NO UN CAJON. Se lanza en vez de caer
     * en "no entendida": si el modelo empieza a inventar desenlaces, hay que verlo
     * en el log, no taparlo con un mensaje amable.
     *
     * -------------------------------------------------------------------------
     * $hayBorradorPrevio: "cambio_de_tema" NO EXISTE EN EL PRIMER TURNO
     *
     * DEFECTO MEDIDO EN PRODUCCION (12-08-2026). Daniel escribio "quiero que me
     * hagas una factura excenta para el cliente plantiflex por 1300 pesos" y el
     * chat le contesto con el mensaje del camino de consultas. La heuristica de
     * ruteo habia hecho bien su trabajo -- la frase entro al armado --, pero el
     * modelo contesto "cambio_de_tema", el turno cayo al camino de consultas y el
     * usuario recibio una respuesta que no pedia, despues de pagar DOS llamadas.
     *
     * POR QUE EL MODELO NO SE EQUIVOCO DEL TODO: ese desenlace significa "esto no
     * continua lo que se venia armando", y en el primer turno NO SE VENIA ARMANDO
     * NADA. La afirmacion era literalmente cierta. El error estaba en ofrecerle
     * una opcion que solo tiene sentido con una conversacion abierta.
     *
     * DOS CAPAS, Y CADA UNA HACE UNA COSA DISTINTA. instrucciones() deja de
     * mencionar el desenlace cuando no aplica -- eso baja la probabilidad casi a
     * cero, pero un prompt es una instruccion y no un contrato. Esta comprobacion
     * es la que GARANTIZA: sin borrador previo, un "cambio_de_tema" es un valor
     * que no estaba en la lista, y se trata igual que cualquier otro desenlace
     * inventado. Mismo criterio que el vocabulario de consultas con el
     * repositorio: uno cuenta que se puede pedir, el otro decide si sirve.
     * -------------------------------------------------------------------------
     */
    private function interpretar(string $texto, bool $hayBorradorPrevio): ArmadoFacturaTraducido
    {
        $sobre = json_decode($texto, true);
        if (! is_array($sobre)) {
            throw TraduccionArmadoException::respuestaIlegible('el cuerpo no es JSON');
        }
        $contenido = $sobre['choices'][0]['message']['content'] ?? null;
        if (! is_string($contenido) || trim($contenido) === '') {
            throw TraduccionArmadoException::respuestaIlegible('no vino choices[0].message.content');
        }

        $dato = json_decode($contenido, true);
        if (! is_array($dato)) {
            throw TraduccionArmadoException::respuestaIlegible(
                'lo que escribio el modelo no es JSON: ' . mb_substr(trim($contenido), 0, 120)
            );
        }

        $desenlace = (string) ($dato['desenlace'] ?? '');
        $borrador  = is_array($dato['borrador'] ?? null) ? $dato['borrador'] : [];

        if ($desenlace === ArmadoFacturaTraducido::CAMBIO_DE_TEMA) {
            if (! $hayBorradorPrevio) {
                throw TraduccionArmadoException::respuestaIlegible(
                    'dijo "cambio_de_tema" en el primer turno, cuando no habia ninguna '
                    . 'conversacion que abandonar. Ese desenlace no se le ofrecio.'
                );
            }

            return ArmadoFacturaTraducido::cambioDeTema();
        }

        if ($desenlace === ArmadoFacturaTraducido::NO_ENTENDIDA) {
            return ArmadoFacturaTraducido::noEntendida(
                $this->textoDe($dato, 'motivo', 'no entendi el pedido; prueba a decirlo de otra forma')
            );
        }

        if ($desenlace === ArmadoFacturaTraducido::FALTAN_DATOS) {
            // SIN PREGUNTA, ESTE DESENLACE NO SIRVE DE NADA: la conversacion se
            // quedaria abierta sin decirle al usuario que falta. Se prefiere un
            // texto de respaldo antes que dejar la pantalla muda.
            return ArmadoFacturaTraducido::faltanDatos(
                $this->textoDe($dato, 'pregunta', 'me falta un dato para armar la factura. ¿Puedes darme mas detalle?'),
                $borrador
            );
        }

        if ($desenlace === ArmadoFacturaTraducido::BORRADOR_LISTO) {
            if ($borrador === []) {
                throw TraduccionArmadoException::respuestaIlegible(
                    'dijo que el borrador estaba listo pero no lo mando'
                );
            }

            // SE DEVUELVE TAL CUAL, SIN VALIDAR NI NORMALIZAR. Validar es del
            // panel: el RUT con Rut::valido(), el cliente con validarCliente(),
            // los largos contra las columnas. Si esta clase corrigiera algo,
            // habria dos validadores y el resultado dependeria de cual dejo pasar
            // que -- mismo criterio que el hermano con las perillas.
            return ArmadoFacturaTraducido::borradorListo($borrador);
        }

        throw TraduccionArmadoException::respuestaIlegible(
            "desenlace desconocido: '" . mb_substr($desenlace, 0, 40) . "'"
        );
    }

    /** @param array<string,mixed> $dato */
    private function textoDe(array $dato, string $clave, string $porDefecto): string
    {
        $v = trim((string) ($dato[$clave] ?? ''));

        return $v !== '' ? $v : $porDefecto;
    }

    /**
     * El prompt de sistema.
     *
     * LAS OPCIONES SALEN DEL VOCABULARIO, NO ESTAN ESCRITAS AQUI -- mismo motivo
     * que en el hermano: si estuvieran, cambiar las formas de pago que acepta la
     * carga masiva dejaria al modelo ofreciendo la lista vieja.
     *
     * Y SE LE DA PERMISO EXPLICITO PARA NO SABER, DOS VECES: puede preguntar
     * (faltan_datos) y puede decir que no entendio. Un modelo al que solo se le
     * ofrece "arma la factura" rellena los huecos con lo que sea, y aqui los
     * huecos son montos y RUT.
     *
     * EL PROMPT CAMBIA SEGUN HAYA O NO ALGO EN CURSO: sin borrador previo, la
     * opcion "cambio_de_tema" NI SE MENCIONA. Ofrecer una opcion que no puede
     * aplicar es invitar a que se use, y eso fue exactamente el defecto de
     * produccion que documenta interpretar(). Las opciones se numeran solas para
     * que quitar una no deje un hueco en la lista.
     */
    private function instrucciones(
        VocabularioArmadoFactura $vocabulario,
        string $hoy,
        bool $hayBorradorPrevio,
        array $avisosDelPanel = [],
    ): string {
        // LOS AVISOS DEL PANEL, SI LOS HAY. Van al final del prompt y con un
        // rotulo que dice de donde salen: el modelo tiene que poder distinguir
        // "esto lo comprobo el sistema" de "esto lo dijo el usuario".
        $bloqueAvisos = '';
        foreach ($avisosDelPanel as $a) {
            $a = trim((string) $a);
            if ($a !== '') {
                $bloqueAvisos .= "- {$a}\n";
            }
        }
        $bloqueAvisos = $bloqueAvisos === ''
            ? ''
            : "\n\nLO QUE EL SISTEMA COMPROBO SOBRE TU RESPUESTA ANTERIOR (esto NO lo dijo el\n"
              . "usuario: lo verifico el sistema contra los datos de la empresa):\n"
              . rtrim($bloqueAvisos)
              . "\nESTOS AVISOS MANDAN sobre lo que traias en el borrador. Si uno dice que un dato\n"
              . "no sirvio, CORRIGELO con lo que el usuario haya escrito despues; no lo repitas.";
        $formas = [
            'Si falta algun dato para armar el borrador (lo mas frecuente):' . "\n"
            . '{"desenlace":"faltan_datos","pregunta":"lo que hay que preguntarle, en una o dos lineas y en español","borrador":{...lo que ya entendiste hasta ahora...}}',

            'Si ya tienes TODO -- y "todo" quiere decir: el cliente, MAS al menos un item' . "\n"
            . '   con su nombre, su cantidad y su precio. Un borrador sin detalle NO esta listo,' . "\n"
            . '   aunque sepas a quien facturarle: preguntalo con "faltan_datos".' . "\n"
            . '{"desenlace":"borrador_listo","borrador":{"cliente":{"rut":"...","nombre":"...","razonSocial":"...","giro":"...","direccion":"...","comuna":"..."},"formaPago":"...","documentos":[{"item":{"nombre":"...","cantidad":1,"precioUnitario":10000,"exento":false}}]}}',
        ];

        // SOLO CON ALGO EN CURSO. En el primer turno no hay nada que abandonar, y
        // "esto no continua lo que se venia armando" seria cierto SIEMPRE.
        if ($hayBorradorPrevio) {
            $formas[] = 'Si el mensaje NO continua lo que se venia armando -- por ejemplo el usuario' . "\n"
                . '   pregunta "cuanto facture en julio" en mitad del armado:' . "\n"
                . '{"desenlace":"cambio_de_tema"}';
        }

        $formas[] = 'Si no entiendes el pedido, o no es sobre facturar:' . "\n"
            . '{"desenlace":"no_entendida","motivo":"explicacion breve y en español para el usuario"}';

        $opciones = '';
        foreach ($formas as $i => $forma) {
            $opciones .= ($i + 1) . ') ' . $forma . "\n\n";
        }
        $opciones = rtrim($opciones);
        $cuantas  = count($formas);

        return <<<TXT
            Ayudas a preparar facturas electronicas chilenas conversando. NO emites nada:
            armas un borrador que despues una persona revisa y confirma. NO inventas RUT,
            precios, cantidades ni nombres: si un dato falta, LO PREGUNTAS.

            La fecha de hoy es {$hoy}. Usala para resolver expresiones como "hoy" o
            "manana", convirtiendolas a fechas concretas AAAA-MM-DD.

            OPCIONES VALIDAS (no uses ninguna otra):
            {$vocabulario->comoTexto()}

            REGLA DE RUTEO -- ESTO ES LO MAS IMPORTANTE Y LO QUE MAS SE MALINTERPRETA:
            Un DOCUMENTO no es lo mismo que un ITEM. Cuando el pedido menciona VARIAS
            COSAS por separado -- por ejemplo "facturale a Perez el diseño y el hosting" --
            se entiende por defecto como VARIAS FACTURAS DE UN ITEM CADA UNA, no como una
            factura con varios items. Cada documento consume un folio, que es un recurso
            limitado, asi que esto cambia el costo real para el usuario.
            PERO SI HAY DUDA DE VERDAD sobre lo que quiso decir, NO ASUMAS: usa
            "faltan_datos" y preguntaselo en una linea, ofreciendo las dos lecturas. No es
            un paso de sobra: es lo que le enseña al usuario como funciona el sistema.

            Cuando el pedido produce VARIOS documentos, cada uno lleva EXACTAMENTE UN item.

            Responde SIEMPRE un unico objeto JSON, sin texto alrededor, con una de estas
            {$cuantas} formas, y NINGUNA OTRA:

            {$opciones}

            REGLAS:
            - Del cliente basta con el RUT si el usuario lo da: el sistema busca el resto.
              Pide nombre, giro, direccion y comuna SOLO si el usuario dice que el cliente
              es nuevo o si el sistema te lo pide en un turno posterior.
            - En "borrador" conserva lo que ya habias entendido en turnos anteriores,
              aunque el mensaje de ahora solo agregue un dato: si lo omites, se pierde.
              PERO CONSERVAR NO ES REPETIR A CIEGAS. Si el usuario corrige un dato, o si
              un aviso del sistema dice que ese dato no sirvio, la version nueva reemplaza
              a la vieja. Vale sobre todo para el nombre del cliente: un nombre que no se
              encontro NO se vuelve a mandar igual.
            - Prefiere preguntar antes que adivinar. Un dato inventado en una factura es
              un problema tributario, no una molestia. NUNCA pongas un item de relleno
              ("Servicio", cantidad 1, precio 0) para poder cerrar el borrador: si no
              sabes que se factura o a que precio, PREGUNTALO.
            - No agregues claves que no esten en las formas de arriba.
            - La pregunta y el motivo se le muestran al usuario tal cual: escribelos claros,
              sin jerga tecnica y sin nombrar columnas, tablas ni claves de este JSON.{$bloqueAvisos}
            TXT;
    }
}
