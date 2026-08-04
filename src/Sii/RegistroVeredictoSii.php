<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use PDO;

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
 * EPR NO SIGNIFICA ACEPTADO. LEER ESTO ANTES DE PINTAR NADA DE VERDE.
 * -----------------------------------------------------------------------------
 * EPR es "Envio Procesado": el SII TERMINO de revisar el sobre. Adentro puede
 * haber documentos aceptados, rechazados y con reparos, y el propio servicio
 * informa esos contadores. ESTA ENTREGA NO LOS PARSEA, a proposito: no tenemos
 * ni una sola respuesta real de getEstUp capturada -- lo unico que hay en el
 * repo son fixtures sinteticos con el RESP_BODY vacio (tests/SiiConsultorTest.php)
 * y ningun documento del SII en docs/ describe ese cuerpo. Parsear a ciegas
 * campos cuyo nombre no conocemos produciria contadores en cero que se leerian
 * como "0 rechazados", que es peor que no tenerlos.
 *
 * El plan es al reves: el runner deja la RESPUESTA CRUDA en su log, y cuando
 * haya respuestas reales se diseña el parseo sobre ellas.
 *
 * QUE EL CANAL DE BOLETAS YA LO HACE es la prueba de que el dato existe y de que
 * la asimetria es solo nuestra: BoletaConsultor::resumir() suma informados,
 * aceptados, rechazados y reparos de estadistica[], y el motor incluso BLOQUEA
 * una anulacion con esos numeros (public/index.php, "la boleta no esta aceptada
 * por el SII"). Para facturas, ese mismo sistema guarda EPR y lo da por bueno.
 *
 * Mientras tanto, EPR no dispara aviso pero tampoco se declara aceptado en
 * ninguna parte: se guarda el codigo crudo y el badge del panel lo muestra en
 * neutro, sin traducir.
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
     * @return int filas MODIFICADAS, no coincidentes. PDO/MySQL cuenta cambios
     *         reales salvo que se active MYSQL_ATTR_FOUND_ROWS, que aqui no se
     *         toca. O sea que reconsultar un sobre que ya tenia este mismo
     *         estado y glosa devuelve 0 aunque el sobre siga teniendo sus N
     *         documentos. El llamador que necesite el tamaño del sobre debe
     *         contarlo aparte y no deducirlo de este numero.
     */
    public static function persistir(
        PDO $pdo,
        string $rutEmisor,
        string $ambiente,
        string $trackId,
        string $estado,
        ?string $glosa,
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

        return $stmt->rowCount();
    }
}
