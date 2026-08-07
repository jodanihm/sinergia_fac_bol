<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use PDO;
use Plantiflex\FacturacionCl\Dto\EstadisticaEnvioSii;
use Throwable;

/**
 * Que hacer con el veredicto que devuelve el SII para un ENVIO (QueryEstUp.jws):
 * como clasificarlo y como persistirlo.
 *
 * POR QUE ESTA CLASE EXISTE, Y NO ES CODIGO SUELTO EN EL HANDLER
 * -----------------------------------------------------------------------------
 * Hoy hay DOS llamadores que reciben ese veredicto y tienen que hacer lo mismo
 * con el: el handler GET /api/v1/dte/{tipo}/{folio}/estado-sii del motor (el
 * boton del panel) y el runner scripts/consultar_veredictos_pendientes.php (el
 * cron). Si cada uno escribiera su propio UPDATE, la clasificacion de rechazos y
 * el alcance del UPDATE se separarian en cuanto alguien tocara uno solo. Ya paso
 * algo asi: el panel resolvia por track_id y el motor por id, y esa diferencia
 * silenciosa es la que convirtio un incidente de 4 documentos en uno de 68.
 *
 *
 * EL VEREDICTO ES DEL SOBRE, NO DEL DOCUMENTO
 * -----------------------------------------------------------------------------
 * QueryEstUp responde por TrackID: el sobre entero. Un sobre lleva hasta 2000
 * DTE (limite del XSD del SII, EnvioDTE_v10.xsd:92) y la facturacion masiva del
 * panel arma sub-lotes de 20 notas. Por eso persistir() actualiza TODAS las
 * filas que comparten (rut_emisor, ambiente, track_id) y no una sola: la
 * respuesta ya las cubria a todas, y guardarla en una sola fila es tirar 19
 * veredictos que el SII ya nos dio.
 *
 *
 * EPR NO SIGNIFICA ACEPTADO, Y AHORA EL CODIGO LO SABE.
 * -----------------------------------------------------------------------------
 * EPR es "Envio Procesado": el SII TERMINO de revisar el sobre. Adentro puede
 * haber documentos aceptados, rechazados y con reparos, y el propio servicio
 * informa esos contadores en el RESP_BODY. Hasta la entrega anterior no se
 * parseaban -- no habia ni una respuesta real capturada -- y un sobre EPR con un
 * rechazo adentro pasaba por bueno. Ya hay dos casos en produccion.
 *
 * Con la respuesta real en la mano (ver SiiConsultor) esto se cierra: el sobre
 * sigue guardandose con su ESTADO, y ADEMAS se guardan los contadores por tipo.
 * La clasificacion pasa a mirar los dos.
 *
 * QUE HACE ESTA ENTREGA CON LOS CONTADORES, Y QUE NO:
 *
 *   SI  detectar y avisar -- un sobre EPR con RECHAZADOS o REPAROS > 0 manda un
 *                     correo, con un asunto distinto al de un sobre rechazado.
 *   SI  guardar    -- los cuatro numeros por tipo (migracion 030), como insumo
 *                     de la entrega que venga.
 *   NO  identificar-- el SII dice CUANTOS, no CUALES. Saber que folio de los
 *                     tres es el rechazado exige getEstDte documento por
 *                     documento, y eso es otra entrega.
 *   NO  tocar totales -- y esto es consecuencia directa de lo anterior. Como no
 *                     se sabe cual, excluir el bloque sacaria de la facturacion
 *                     los documentos BUENOS del mismo tipo: seis notas de
 *                     credito por tres observadas. EstadoContable queda intacto.
 *
 * EL ESTADO TAMPOCO SE TOCA. Un EPR con rechazos sigue guardandose como 'EPR' y
 * no como un codigo inventado, por dos motivos medidos: CertificacionEstadoResolver
 * aprueba el Set Basico exigiendo estado === 'EPR' (le romperiamos la
 * certificacion a quien la tenga en vuelo), y EstadoContable clasifica por
 * estado con una lista de codigos del SII, no nuestros.
 */
final class RegistroVeredictoSii
{
    /**
     * Estados que el SII usa para decir que RECHAZO el sobre.
     *
     * DE DONDE SALE LA LISTA, porque no la invente: es la enumeracion de
     * docs/44_API_Boleta_Electronica_OpenAPI_Spec.yaml (campo `estado` de
     * ResultadoEnvioDataRespuesta), que es el UNICO listado con glosa oficial
     * que existe en el repo. Su limitacion hay que decirla: esta publicado para
     * el canal REST de BOLETAS, no para el SOAP de facturas. Para el SOAP no hay
     * ningun documento en docs/ que enumere estados -- el docblock de
     * SiiConsultor solo dice "EPR, RCT/RFR/RSC, -11, etc.", y ese "etc." es
     * justamente lo que esta lista no puede cerrar.
     *
     * Por eso la lista NO es la que decide sola: lo que decide es esRechazo(),
     * donde lo que no reconocemos tambien avisa.
     *
     *   RCH  Rechazado por errores en informacion
     *   RCO  Rechazado por consistencia
     *   RCT  Rechazado por Error en Caratula   <- el del incidente de las 68
     *   RFR  Rechazado por error en firma
     *   RSC  Rechazado por Schema
     *   RPT  Envio Repetido Rechazado
     *   VOF  No se encontro el archivo .xml
     *   RPR  "Aceptado con Reparos" -- SI avisa, y es una decision deliberada:
     *        un reparo es algo que alguien tiene que mirar, y el costo de un
     *        correo de mas es incomparablemente menor que el de 68 documentos
     *        que nadie miro.
     *
     * @var list<string>
     */
    public const ESTADOS_RECHAZO = ['RCH', 'RCO', 'RCT', 'RFR', 'RSC', 'RPT', 'VOF', 'RPR'];

    /**
     * Estados en los que el sobre TODAVIA se esta procesando: no son veredicto,
     * asi que no avisan y el sobre se vuelve a consultar en la corrida
     * siguiente.
     *
     *   REC  Envio recibido          SOK  Schema Validado
     *   FOK  Firma de Envio Validada CRT  Caratula OK
     *   PRD  Envio en Proceso        -11  en proceso (sin glosa oficial; sale
     *                                     del docblock de SiiConsultor)
     *
     * @var list<string>
     */
    public const ESTADOS_EN_CURSO = ['REC', 'SOK', 'FOK', 'PRD', 'CRT', '-11'];

    /** Envio procesado por el SII. NO quiere decir aceptado: ver el docblock. */
    public const ESTADO_PROCESADO = 'EPR';

    /** Lo que se guarda cuando el SII responde sin ESTADO. Ver normalizar(). */
    public const ESTADO_DESCONOCIDO = 'desconocido';

    /** Motivos del aviso. Ver motivoAviso() y glosaMotivo(). */
    public const AVISO_NINGUNO                 = 'ninguno';
    public const AVISO_SOBRE_RECHAZADO         = 'sobre_rechazado';
    public const AVISO_DOCUMENTOS_RECHAZADOS   = 'documentos_rechazados';
    public const AVISO_DOCUMENTOS_CON_REPAROS  = 'documentos_con_reparos';
    public const AVISO_CONTADORES_ILEGIBLES    = 'contadores_ilegibles';

    /** Techo de glosa_sii en la migracion 027. */
    private const GLOSA_MAX = 255;

    /**
     * Normaliza el ESTADO que devolvio el SII.
     *
     * POR QUE ESTO NO PUEDE DEVOLVER CADENA VACIA. SiiConsultor::primerTexto()
     * devuelve '' cuando el tag no aparece en la respuesta, y dte_emitido.estado
     * es NOT NULL sin default: hasta esta entrega, una respuesta sin <ESTADO>
     * SOBREESCRIBIA el 'enviado' del documento con '', y el badge del panel lo
     * pintaba como "Sin estado". O sea que un fallo de lectura borraba el unico
     * rastro de que el documento se habia mandado.
     *
     * Con 'desconocido' el documento queda marcado como lo que es: consultado,
     * sin respuesta legible. Y ademas cuenta como rechazo para el aviso, porque
     * una respuesta que no sabemos leer no es una buena noticia.
     */
    public static function normalizar(string $estado): string
    {
        $e = trim($estado);

        return $e !== '' ? $e : self::ESTADO_DESCONOCIDO;
    }

    /**
     * ¿Este veredicto merece un aviso?
     *
     * EL DEFAULT ES AVISAR, y esa es la regla de la entrega: lo desconocido NO
     * se trata como bueno. Solo callan los estados que sabemos nombrar y que no
     * son un rechazo -- EPR y los de proceso. Cualquier codigo que el SII
     * invente, cambie o que nosotros no hayamos visto todavia entra por aqui y
     * avisa.
     *
     * Es la conducta inversa a la que costo 68 documentos: antes, todo lo que no
     * se entendia se guardaba en silencio.
     */
    public static function esRechazo(string $estado): bool
    {
        $e = self::normalizar($estado);

        if ($e === self::ESTADO_PROCESADO) {
            return false;
        }
        if (in_array($e, self::ESTADOS_EN_CURSO, true)) {
            return false;
        }

        return true;
    }

    /** ¿Sigue en proceso, o sea hay que volver a consultarlo? */
    public static function enCurso(string $estado): bool
    {
        return in_array(self::normalizar($estado), self::ESTADOS_EN_CURSO, true);
    }

    /**
     * ¿El sobre trae malas noticias ADENTRO, aunque su estado sea bueno?
     *
     * Este es el agujero que cierra la entrega: el estado dice EPR, esRechazo()
     * dice false, y adentro hay un tipo con RECHAZADOS 1 de 1 informado.
     *
     * TRES COSAS CUENTAN COMO MALA NOTICIA, y las tres por el mismo criterio que
     * el resto de esta clase -- lo que no se puede afirmar bueno, alerta:
     *
     *   RECHAZADOS > 0   el SII rechazo documentos del sobre.
     *   REPAROS    > 0   el SII acepto con observaciones. Alguien tiene que
     *                    mirarlo; es el mismo criterio por el que RPR esta en
     *                    ESTADOS_RECHAZO desde la entrega anterior.
     *   BLOQUE ILEGIBLE  un bloque al que le falta un campo o trae basura. No
     *                    sabemos si tenia rechazos, asi que no se declara bueno.
     *
     * SIN BLOQUES devuelve false, y es correcto: los sobres que no son EPR no
     * traen RESP_BODY, y para esos ya decide esRechazo() con el estado.
     *
     * NO TIENE LOGICA PROPIA: pregunta lo mismo que motivoAviso() y responde
     * si/no. Que fueran dos recorridos distintos seria la misma trampa que este
     * archivo denuncia en su cabecera -- dos clasificaciones que se parecen hoy
     * y se separan en cuanto alguien toque una.
     *
     * @param list<EstadisticaEnvioSii> $estadistica
     */
    public static function rechazoInterno(array $estadistica): bool
    {
        return self::motivoInterno($estadistica) !== self::AVISO_NINGUNO;
    }

    /**
     * Que tipo de mala noticia es, para que el ASUNTO del correo lo diga.
     *
     * Un sobre RECHAZADO y un sobre PROCESADO CON RECHAZOS ADENTRO no son lo
     * mismo y el segundo es mucho mas confuso de leer: el estado dice EPR, que
     * es la palabra que todo el mundo asocia con "salio bien". Si los dos
     * llegaran con el mismo asunto, el que importa se leeria como ruido.
     *
     * @param list<EstadisticaEnvioSii> $estadistica
     */
    public static function motivoAviso(string $estado, array $estadistica): string
    {
        // EL ESTADO MANDA CUANDO ES MALO. Decirle "procesado con rechazos" a un
        // sobre que el SII no llego a procesar seria describirlo mal, y ademas el
        // RESP_BODY de esos sobres viene vacio de todas formas.
        if (self::esRechazo($estado)) {
            return self::AVISO_SOBRE_RECHAZADO;
        }

        return self::motivoInterno($estadistica);
    }

    /**
     * La clasificacion mirando SOLO los contadores. El orden importa: si un
     * sobre tiene rechazos Y reparos, lo que hay que leer en el asunto es el
     * rechazo.
     *
     * @param list<EstadisticaEnvioSii> $estadistica
     */
    private static function motivoInterno(array $estadistica): string
    {
        $rechazados = 0;
        $reparos    = 0;
        $ilegible   = false;

        foreach ($estadistica as $b) {
            if (! $b->completo()) {
                $ilegible = true;
                continue;
            }
            $rechazados += (int) $b->rechazados;
            $reparos    += (int) $b->reparos;
        }

        if ($rechazados > 0) {
            return self::AVISO_DOCUMENTOS_RECHAZADOS;
        }
        if ($reparos > 0) {
            return self::AVISO_DOCUMENTOS_CON_REPAROS;
        }
        if ($ilegible) {
            return self::AVISO_CONTADORES_ILEGIBLES;
        }

        return self::AVISO_NINGUNO;
    }

    /**
     * Frase para el asunto del correo. Las cuatro se leen de un vistazo en una
     * bandeja y dicen cosas distintas a proposito.
     */
    public static function glosaMotivo(string $motivo): string
    {
        return match ($motivo) {
            self::AVISO_SOBRE_RECHAZADO       => 'Envio RECHAZADO',
            self::AVISO_DOCUMENTOS_RECHAZADOS => 'Envio procesado CON DOCUMENTOS RECHAZADOS',
            self::AVISO_DOCUMENTOS_CON_REPAROS => 'Envio procesado CON REPAROS',
            self::AVISO_CONTADORES_ILEGIBLES  => 'Envio procesado, CONTADORES ILEGIBLES',
            default                            => 'Envio procesado',
        };
    }

    /**
     * Persiste el veredicto en TODAS las filas del sobre.
     *
     * El WHERE lleva rut_emisor y ambiente ademas del track_id, y no por
     * prolijidad: track_id es un identificador del SII, no nuestro, y no hay
     * ninguna restriccion en la base que impida que dos emisores distintos
     * tengan uno igual. Sin esas dos columnas, el veredicto de un tenant podria
     * escribirse sobre los documentos de otro.
     *
     * La glosa se TRUNCA aqui, explicitamente, al techo de la columna: una glosa
     * cortada sigue explicando el problema; un UPDATE que revienta por unos
     * caracteres de mas, no. Y depender del sql_mode de la instalacion para que
     * corte o falle seria depender de una configuracion que este codigo no
     * controla.
     *
     * LOS CONTADORES VAN EN UN UPDATE POR TIPO, no en el de arriba, porque su
     * granularidad es otra: el estado es del SOBRE y los contadores son de
     * (sobre, tipo de documento). Un sobre con tres tipos hace un UPDATE de
     * estado y tres de contadores, cada uno acotado con tipo_dte al conjunto de
     * filas que ese bloque describe.
     *
     * LOS BLOQUES ILEGIBLES NO SE ESCRIBEN. Dejarlos en 0 seria escribir "cero
     * rechazados" sobre algo que no pudimos leer. Quedan en el default de la
     * columna, y no se pierde nada: el aviso ya los delato, porque se calcula
     * sobre la respuesta parseada y no leyendo estas columnas.
     *
     * Y GUARDAR LOS CONTADORES NUNCA PUEDE ROMPER EL GUARDADO DEL VEREDICTO: ver
     * la guarda de mas abajo. El UPDATE del estado NO esta protegido, a
     * proposito, porque ese si es lo esencial y tiene que fallar ruidoso.
     *
     * @param list<EstadisticaEnvioSii> $estadistica vacio = no tocar contadores.
     *
     * @return int filas MODIFICADAS por el UPDATE del ESTADO, no coincidentes.
     *         PDO/MySQL cuenta cambios reales salvo que se active
     *         MYSQL_ATTR_FOUND_ROWS, que aqui no se toca. O sea que reconsultar
     *         un sobre que ya tenia este mismo estado y glosa devuelve 0 aunque
     *         el sobre siga teniendo sus N documentos. El llamador que necesite
     *         el tamaño del sobre debe contarlo aparte y no deducirlo de este
     *         numero.
     */
    public static function persistir(
        PDO $pdo,
        string $rutEmisor,
        string $ambiente,
        string $trackId,
        string $estado,
        ?string $glosa,
        array $estadistica = [],
    ): int {
        $g = $glosa !== null ? trim($glosa) : '';
        $g = $g !== '' ? mb_substr($g, 0, self::GLOSA_MAX) : null;

        $stmt = $pdo->prepare(
            'UPDATE dte_emitido SET estado = :estado, glosa_sii = :glosa '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND track_id = :track'
        );
        $stmt->execute([
            ':estado' => self::normalizar($estado),
            ':glosa'  => $g,
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente,
            ':track'  => $trackId,
        ]);
        $filas = $stmt->rowCount();

        // ---------------------------------------------------------------
        //  GUARDAR LOS CONTADORES NO PUEDE TUMBAR EL VEREDICTO.
        //
        //  MISMA REGLA QUE EL ENCOLADO DE CORREO, palabra por palabra: "ENCOLAR
        //  NUNCA PUEDE ROMPER UNA EMISION. Cualquier fallo se registra y se
        //  sigue; jamas convierte un 201 en un 500" (EncoladorCorreo, docblock
        //  de la clase). Aca la jerarquia es la misma: el VEREDICTO es lo
        //  esencial -- cuando se llega a esta linea el UPDATE de arriba ya lo
        //  guardo -- y los contadores son un EXTRA que ni siquiera se lee
        //  todavia, porque no tocan ningun total.
        //
        //  Y NO ES HIPOTETICO. El 04-08-2026 el runner murio entero por una
        //  columna que no existia. La primera corrida del A/B de certificacion
        //  volvio a mostrarlo exacto: el estado y la glosa se escribieron BIEN,
        //  y despues la PDOException de las columnas de la 030 escapo al
        //  llamador. En produccion eso es un 500 en la cara del usuario DESPUES
        //  de haber guardado bien lo importante, sin manera de que lo sepa.
        //
        //  EL ALCANCE DE LA GUARDA ES SOLO ESTA LLAMADA. El UPDATE del estado y
        //  la glosa queda fuera a proposito: si ESE falla hay que enterarse a
        //  gritos, porque es la unica escritura que el sistema no puede perder.
        // ---------------------------------------------------------------
        try {
            self::persistirEstadistica($pdo, $rutEmisor, $ambiente, $trackId, $estadistica);
        } catch (Throwable $e) {
            // No se traga en silencio: queda en el log de errores, que en el
            // runner es el mismo archivo del cron (2>&1) y en el motor y el
            // panel el error_log de PHP. El prefijo es buscable.
            error_log(sprintf(
                'RegistroVeredictoSii: no se pudieron guardar los contadores del sobre '
                . '(rut=%s ambiente=%s track=%s bloques=%d). EL VEREDICTO SI QUEDO GUARDADO. '
                . '¿Falta la migracion 030? Detalle: %s',
                $rutEmisor,
                $ambiente,
                $trackId,
                count($estadistica),
                $e->getMessage(),
            ));
        }

        return $filas;
    }

    /**
     * Los cuatro contadores en las filas del sobre que son de ese tipo.
     *
     * Privado a proposito: no hay ningun caso en que tenga sentido guardar los
     * contadores sin guardar el estado del que salieron. Que sea un solo punto
     * de entrada es lo que impide que un llamador nuevo se olvide de la mitad --
     * el mismo argumento por el que persistir() existe.
     *
     * @param list<EstadisticaEnvioSii> $estadistica
     */
    private static function persistirEstadistica(
        PDO $pdo,
        string $rutEmisor,
        string $ambiente,
        string $trackId,
        array $estadistica,
    ): void {
        $completos = array_filter($estadistica, static fn (EstadisticaEnvioSii $b): bool => $b->completo());
        if ($completos === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE dte_emitido SET sii_informados = :inf, sii_aceptados = :ace, '
            . 'sii_rechazados = :rec, sii_reparos = :rep '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND track_id = :track AND tipo_dte = :tipo'
        );

        foreach ($completos as $b) {
            $stmt->execute([
                ':inf'   => $b->informados,
                ':ace'   => $b->aceptados,
                ':rec'   => $b->rechazados,
                ':rep'   => $b->reparos,
                ':rut'   => $rutEmisor,
                ':amb'   => $ambiente,
                ':track' => $trackId,
                ':tipo'  => $b->tipoDocto,
            ]);
        }
    }
}
