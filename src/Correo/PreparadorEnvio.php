<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Correo;

use PDO;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;
use Plantiflex\FacturacionCl\Pdf\DtePdfGenerator;
use Throwable;

/**
 * Arma el correo de UNA fila de la cola dte_envio_correo, y registra su
 * resultado.
 *
 * POR QUE EXISTE: hay DOS entradas al mismo envio -- scripts/enviar_correo.php,
 * que manda un documento por su id, y scripts/enviar_correos_pendientes.php,
 * que vacia la cola. Sin esta clase las dos tendrian su propia copia de la
 * consulta, las guardas, la generacion del PDF y el armado del mensaje, y
 * cualquier arreglo habria que hacerlo dos veces. El camino de envio es UNO.
 *
 * NO ENVIA NI DECIDE POLITICA. No conoce topes, ni presupuestos, ni reintentos:
 * eso es del runner. Aqui solo se prepara y se registra.
 *
 * QUE SIGNIFICA estado='enviado' -- LEER ESTO ANTES DE CONFIAR EN ESA COLUMNA:
 *
 *   'enviado' quiere decir "BREVO ACEPTO EL MENSAJE", NO "el receptor lo
 *   recibio". Son cosas distintas y la API no permite distinguirlas al enviar.
 *
 *   Si el destinatario esta en la lista de bloqueo de Brevo (rebote duro
 *   previo, queja de spam, baja voluntaria), la API responde 2xx igual y el
 *   correo NUNCA se entrega. El bloqueo aparece despues y por otro canal: como
 *   evento 'blocked' en los logs transaccionales o via webhook. No hay endpoint
 *   para consultar una direccion suelta -- GET /v3/smtp/blockedContacts es
 *   paginado por fechas y filtra por REMITENTE, no por destinatario.
 *
 *   Se acepta ese punto ciego a proposito. La mitigacion es que el runner deja
 *   el messageId de Brevo en su linea de log: convierte un "no me llego" en una
 *   busqueda exacta en el panel de Brevo. Cerrarlo de verdad exige recibir los
 *   webhooks de Brevo, y eso es una entrega futura.
 */
final class PreparadorEnvio
{
    /**
     * COPIA DELIBERADA de TIPOS_PERMITIDOS_PDF de public/index.php (linea ~73).
     *
     * No se puede reusar la original: es una const de ESE archivo, que es el
     * front controller del motor, e incluirlo desde un CLI dispararia Auth, la
     * sesion y el router. Los generadores de PDF tampoco validan el tipo por su
     * cuenta -- quien filtra es pdfDte() alla y esta clase aca.
     *
     * SI SE AGREGA O QUITA UN TIPO, HAY QUE TOCAR LOS DOS SITIOS. El front
     * controller lleva el comentario espejo avisando de esta copia.
     */
    public const TIPOS_CON_PDF = [33, 34, 61, 56, 39];

    // El mapa de nombres que vivia aqui se elimino: ahora sale de
    // TipoDte::nombreDe($tipo, largo: true), que es la unica fuente del
    // proyecto. Este es el UNICO sitio que usa el nombre LARGO (con
    // "electronica"), porque el asunto de un correo es lo unico que ve un
    // tercero fuera del panel; en la interfaz manda el nombre corto.

    /**
     * Prepara el correo de una fila, o explica por que no se puede enviar.
     *
     * En caso de NO poder, devuelve ademas por que canal reportarlo y con que
     * exit code, para que los dos CLI se comporten igual: un "ya estaba
     * enviada" es un no-op informativo que va a STDOUT, y un "no tiene XML" es
     * un error que va a STDERR.
     *
     * @return array{ok:bool, motivo?:string, canal?:string, codigo?:int,
     *               destinatario?:string, asunto?:string, cuerpo?:string,
     *               adjuntos?:list<array{nombre:string,contenido:string}>,
     *               replyTo?:?string, remitenteNombre?:?string,
     *               tipoDte?:int, folio?:int, rutEmisor?:string,
     *               estado?:string, intentos?:int}
     */
    public static function preparar(PDO $pdo, int $envioId): array
    {
        // TODOS LOS JOIN VAN POR id NUMERICO. El esquema vive en dos familias de
        // collation: las tablas del motor son utf8mb4_0900_ai_ci y las creadas
        // por las migraciones del panel son utf8mb4_unicode_ci. Cruzarlas por
        // una columna de TEXTO (un rut, por ejemplo) revienta con "Illegal mix
        // of collations". Por BIGINT no hay collation que mezclar:
        //
        //     dte_envio_correo.dte_emitido_id -> dte_emitido.id
        //     dte_envio_correo.cuenta_id      -> dte_emisor.cuenta_id
        //     dte_envio_correo.cuenta_id      -> cuenta.id
        //
        // dte_envio_correo ya guarda cuenta_id y destinatario como FOTO, tomada
        // al encolar, justamente para no depender de esos cruces.
        $stmt = $pdo->prepare(
            'SELECT q.id, q.estado, q.destinatario, q.intentos, q.cuenta_id, '
            . '       e.tipo_dte, e.folio, e.rut_emisor, e.xml, '
            . '       em.razon_social, '
            . '       c.email AS cuenta_email '
            . 'FROM dte_envio_correo q '
            . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
            . "LEFT JOIN dte_emisor em ON em.cuenta_id = q.cuenta_id AND em.ambiente = 'produccion' "
            . 'LEFT JOIN cuenta c ON c.id = q.cuenta_id '
            . 'WHERE q.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $envioId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return self::no("No existe la fila {$envioId} en dte_envio_correo.", 'stderr', 1);
        }

        // --- Guardas: nunca reenviar, nunca enviar a la nada -----------------
        if ($fila['estado'] !== 'pendiente') {
            return self::no("Fila {$envioId}: estado '{$fila['estado']}', no 'pendiente'. NO se envia nada.", 'stdout', 1);
        }
        $destinatario = trim((string) ($fila['destinatario'] ?? ''));
        if ($destinatario === '') {
            return self::no("Fila {$envioId}: sin destinatario. NO se envia nada.", 'stdout', 1);
        }
        $tipoDte = (int) $fila['tipo_dte'];
        $folio   = (int) $fila['folio'];
        if (! in_array($tipoDte, self::TIPOS_CON_PDF, true)) {
            return self::no("Fila {$envioId}: tipo {$tipoDte} no tiene generador de PDF. NO se envia nada.", 'stdout', 1);
        }

        // EL XML PUEDE FALTAR, Y ES POR DISENO: persistirEmitido() del motor es
        // best-effort y se traga sus errores, asi que hay filas de dte_emitido
        // sin xml (el propio MySqlDteEmitidoRepository::obtenerXml las trata
        // como "sin XML").
        $xmlBytes = (string) ($fila['xml'] ?? '');
        if ($xmlBytes === '') {
            return self::no(
                "Fila {$envioId}: dte_emitido {$tipoDte}/{$folio} no tiene XML guardado; no hay nada que adjuntar.",
                'stderr',
                1
            );
        }

        // --- El PDF, generado en proceso desde ESOS MISMOS BYTES -------------
        try {
            $pdfBytes = $tipoDte === 39
                ? (new BoletaPdfGenerator())->generarDesdeEnvioXml($xmlBytes, $tipoDte, $folio)
                : (new DtePdfGenerator())->generarDesdeEnvioXml($xmlBytes, false, $tipoDte, $folio);
        } catch (Throwable $e) {
            return self::no("Fila {$envioId}: fallo la generacion del PDF - " . $e->getMessage(), 'stderr', 3);
        }

        // --- El correo -------------------------------------------------------
        $etiquetaTipo = TipoDte::nombreDe($tipoDte, largo: true);
        $razonSocial  = trim((string) ($fila['razon_social'] ?? ''));
        $replyTo      = trim((string) ($fila['cuenta_email'] ?? ''));
        $rutEmisor    = (string) $fila['rut_emisor'];
        $nombreVisible = $razonSocial !== '' ? $razonSocial : $rutEmisor;

        $asunto = sprintf('%s N %d - %s', $etiquetaTipo, $folio, $nombreVisible);

        $cuerpo = sprintf(
            "<p>Estimado(a),</p>\n"
            . "<p>Adjuntamos su <strong>%s N&deg; %d</strong>, emitida por <strong>%s</strong> (RUT %s).</p>\n"
            . "<p>Se adjuntan dos archivos:</p>\n"
            . "<ul><li>El XML con firma electronica, valido ante el SII.</li>\n"
            . "<li>Una representacion impresa en PDF.</li></ul>\n"
            . "<p>Si necesita responder, puede hacerlo directamente a este correo.</p>\n",
            htmlspecialchars($etiquetaTipo, ENT_QUOTES, 'UTF-8'),
            $folio,
            htmlspecialchars($nombreVisible, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($rutEmisor, ENT_QUOTES, 'UTF-8')
        );

        // Nombres que sirvan de verdad al abrir el correo: RUT_tipo_folio.
        $baseNombre = sprintf('%s_%d_%d', str_replace('.', '', $rutEmisor), $tipoDte, $folio);

        // EL XML VA EN BYTES CRUDOS, TAL COMO SALIO DE LA BASE.
        //
        // NADA de mb_convert_encoding, utf8_encode, htmlspecialchars ni
        // normalizacion de saltos de linea sobre $xmlBytes. Ese XML esta FIRMADO
        // y va en ISO-8859-1: cualquier transcodificacion, por inocente que
        // parezca, cambia los bytes sobre los que se calculo la firma y la
        // invalida ante el SII. El receptor recibiria un documento que no valida.
        //
        // El base64 lo hace BrevoMailer::enviar() sobre estos mismos bytes, en
        // un solo paso y sin tocarlos.
        return [
            'ok'              => true,
            'destinatario'    => $destinatario,
            'asunto'          => $asunto,
            'cuerpo'          => $cuerpo,
            'adjuntos'        => [
                ['nombre' => $baseNombre . '.xml', 'contenido' => $xmlBytes],
                ['nombre' => $baseNombre . '.pdf', 'contenido' => $pdfBytes],
            ],
            'replyTo'         => $replyTo !== '' ? $replyTo : null,
            'remitenteNombre' => $razonSocial !== '' ? $razonSocial : null,
            'tipoDte'         => $tipoDte,
            'folio'           => $folio,
            'rutEmisor'       => $rutEmisor,
            'estado'          => (string) $fila['estado'],
            'intentos'        => (int) $fila['intentos'],
        ];
    }

    /** @return array{ok:false, motivo:string, canal:string, codigo:int} */
    private static function no(string $motivo, string $canal, int $codigo): array
    {
        return ['ok' => false, 'motivo' => $motivo, 'canal' => $canal, 'codigo' => $codigo];
    }

    /**
     * Deja constancia del intento. LA FILA NUNCA QUEDA 'pendiente' DESPUES DE
     * INTENTAR: o queda 'enviado', o queda 'error' con su mensaje.
     *
     * intentos SIEMPRE sube, en exito y en fallo: es el contador de intentos, no
     * el de fracasos, y es lo que permite al runner dejar de reintentar una fila
     * que no tiene arreglo.
     */
    public static function registrarResultado(PDO $pdo, int $envioId, bool $ok, string $detalle): void
    {
        if ($ok) {
            $pdo->prepare(
                "UPDATE dte_envio_correo SET estado = 'enviado', enviado_at = NOW(), "
                . 'intentos = intentos + 1, ultimo_error = NULL WHERE id = :id'
            )->execute([':id' => $envioId]);

            return;
        }

        $pdo->prepare(
            "UPDATE dte_envio_correo SET estado = 'error', intentos = intentos + 1, "
            . 'ultimo_error = :err WHERE id = :id'
        )->execute([':err' => substr($detalle, 0, 500), ':id' => $envioId]);
    }
}
