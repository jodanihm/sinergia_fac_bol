<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Correo;

use PDO;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;
use Plantiflex\FacturacionCl\Pdf\DtePdfGenerator;
use Plantiflex\FacturacionCl\Pdf\LogoEmpresa;
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

    /**
     * Los tipos que pueden llevar link de pago en el correo.
     *
     * SOLO LOS QUE SE COBRAN. La nota de credito (61) DEVUELVE dinero: mandarla
     * con un boton de pagar seria, en el mejor caso, confuso, y en el peor haria
     * que alguien pagara de mas. La nota de debito (56) es un ajuste que casi
     * nunca se cobra por esta via. La boleta (39) queda fuera por otro motivo
     * distinto: hoy el panel no encola correo de boletas, asi que no hay donde
     * poner el link.
     *
     * ES LA SEGUNDA LINEA DE DEFENSA, no la primera: quien decide de verdad es
     * ResolutorLinkPago, que ni siquiera crea la orden para un tipo que no
     * corresponde. Esta se comprueba igual, aqui, porque es lo ultimo que pasa
     * antes de que lo lea un tercero.
     */
    public const TIPOS_CON_LINK_PAGO = [33, 34];

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
        //     dte_envio_correo.dte_emitido_id -> dte_pago_link.dte_emitido_id
        //
        // dte_envio_correo ya guarda cuenta_id y destinatario como FOTO, tomada
        // al encolar, justamente para no depender de esos cruces.
        $stmt = $pdo->prepare(
            'SELECT q.id, q.estado, q.destinatario, q.intentos, q.cuenta_id, '
            . '       e.tipo_dte, e.folio, e.rut_emisor, e.xml, '
            // forma_pago y fecha_vencimiento (migracion 026): el cuerpo del
            // correo agrega una linea con el vencimiento cuando la factura es a
            // credito. Los documentos emitidos ANTES de esa migracion tienen las
            // dos en NULL y no llevan la linea.
            . '       e.forma_pago, e.fecha_vencimiento, '
            . '       em.razon_social, '
            . '       c.email AS cuenta_email, '
            // La orden de pago del documento, si es que existe (migracion 050).
            // AQUI SOLO SE LEE: esta clase no decide si corresponde cobrar ni
            // llama a ninguna pasarela -- eso seria decidir politica, que su
            // contrato dice que no hace, y ademas --dry-run ejecuta este metodo
            // y crearia cobros de verdad. Quien crea la orden es
            // ResolutorLinkPago, antes, desde el runner.
            . '       p.url AS pago_url, p.estado AS pago_estado, p.monto AS pago_monto, '
            // EL AMBIENTE DE LA ORDEN, NO EL DE LA CUENTA.
            //
            // Sale de dte_pago_link (migracion 054), donde se congelo al crear la
            // orden. Antes salia de pago_pasarela_cuenta, o sea de la
            // configuracion del momento del ENVIO: un correo de una orden creada
            // en sandbox que se mandara despues de pasar a produccion habria
            // perdido su aviso de PRUEBA. El aviso tiene que hablar del link que
            // lleva el correo, no de lo que la empresa este haciendo hoy.
            //
            // Y no se adivina mirando si la url dice "sandbox": eso ataria un
            // aviso de "esto no cobra de verdad" a como Flow decida nombrar sus
            // dominios manana, y equivocarse en el sentido peligroso -- no avisar
            // en una prueba -- es hacer que alguien crea que pago.
            . '       p.ambiente AS pago_ambiente '
            . 'FROM dte_envio_correo q '
            . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
            . "LEFT JOIN dte_emisor em ON em.cuenta_id = q.cuenta_id AND em.ambiente = 'produccion' "
            . 'LEFT JOIN cuenta c ON c.id = q.cuenta_id '
            . 'LEFT JOIN dte_pago_link p ON p.dte_emitido_id = q.dte_emitido_id '
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
        //
        // El logo es lo UNICO que no sale de esos bytes: no viaja en el XML. Va
        // en su propia consulta y no en el SELECT de arriba, para no meter un
        // MEDIUMBLOB en una fila que ya trae el XML entero -- y porque
        // dte_logo.rut_emisor y dte_emitido.rut_emisor estan en familias de
        // collation distintas y un JOIN por texto necesitaria COLLATE explicito
        // (ver la migracion 031).
        $logo = LogoEmpresa::paraTcpdf(LogoEmpresa::leer($pdo, (string) $fila['rut_emisor']));

        try {
            $pdfBytes = $tipoDte === 39
                ? (new BoletaPdfGenerator())->generarDesdeEnvioXml($xmlBytes, $tipoDte, $folio)
                : (new DtePdfGenerator())->generarDesdeEnvioXml($xmlBytes, false, $tipoDte, $folio, $logo);
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

        // LINEA DE VENCIMIENTO. Aparece SOLO con credito (forma_pago = 2) Y con
        // fecha. En cualquier otro caso el cuerpo queda exactamente como antes,
        // sin una linea de mas.
        //
        // NO SE INVENTA NADA PARA LOS DOCUMENTOS VIEJOS. Los emitidos antes de la
        // migracion 026 tienen forma_pago en NULL, y aunque el SII lea ese
        // silencio como credito (Formato DTE v2.5, pag. 14), aqui no se asume:
        // sin dato, sin linea. Escribirle a un receptor "vence el X" a partir de
        // una suposicion seria peor que no decirle nada.
        //
        // Tampoco va linea para contado ni para sin costo: no le dicen nada
        // accionable a quien recibe la factura.
        //
        // El vencimiento NO va en el PDF, y es deliberado: el Manual de Muestras
        // Impresas v4.0 no lo exige (no menciona el campo en ninguna de sus 51
        // paginas) y dibujarlo obligaria a parchear LibreDTE bajo oracle/, con
        // una certificacion de muestras impresas en revision. El correo es codigo
        // propio y resuelve la necesidad real sin esa deuda.
        //
        // FORMATO DE FECHA: dd-mm-aaaa, y es EL UNICO SITIO DEL PROYECTO QUE SE
        // APARTA DEL ISO A PROPOSITO.
        //
        // El panel muestra AAAA-MM-DD, medido: sus tablas pintan la fecha cruda
        // de la base (documentos-listado, facturacion-masiva-form "Vence
        // 2026-09-30") y el unico formateador deliberado de una vista,
        // $fmtFechaHora de carga-masiva-form, produce 'Y-m-d H:i'. Los dos
        // 'd-m-Y' que existen en el panel no son convencion de interfaz: uno es
        // el formato que EXIGE un formulario del SII (formatearFechaAvanceSii) y
        // el otro es el pie del informe PDF.
        //
        // AQUI NO ES INCONSISTENCIA, ES DIFERENCIA DE AUDIENCIA. El panel lo lee
        // el operador; este correo lo lee un TERCERO que recibe una factura, y en
        // Chile una fecha para una persona se escribe dd-mm-aaaa. El AAAA-MM-DD
        // se queda en el panel y en el XML, que es donde corresponde.
        $lineaVencimiento = '';
        $formaPago        = $fila['forma_pago'] !== null ? (int) $fila['forma_pago'] : null;
        $vencimiento      = trim((string) ($fila['fecha_vencimiento'] ?? ''));
        if ($formaPago === 2 && $vencimiento !== '') {
            $lineaVencimiento = sprintf(
                "<p>Esta factura es a <strong>credito</strong> y vence el <strong>%s</strong>.</p>\n",
                htmlspecialchars(
                    (new \DateTimeImmutable($vencimiento))->format('d-m-Y'),
                    ENT_QUOTES,
                    'UTF-8'
                )
            );
        }

        // EL LINK DE PAGO. Va DESPUES del vencimiento y ANTES de los adjuntos, y
        // ese orden es el que se lee bien: que documento es, cuando vence, como
        // se paga, que viene adjunto.
        $bloquePago = self::bloqueLinkPago(
            $fila['pago_url'] ?? null,
            $fila['pago_estado'] ?? null,
            isset($fila['pago_monto']) ? (int) $fila['pago_monto'] : null,
            $tipoDte,
            $folio,
            $etiquetaTipo,
            $fila['pago_ambiente'] ?? null
        );

        // El correo se manda SOLO como HTML: BrevoMailer arma el payload con
        // 'htmlContent' y no envia parte de texto plano, asi que la linea va en un
        // unico lugar.
        $cuerpo = sprintf(
            "<p>Estimado(a),</p>\n"
            . "<p>Adjuntamos su <strong>%s N&deg; %d</strong>, emitida por <strong>%s</strong> (RUT %s).</p>\n"
            . '%s'
            . '%s'
            . "<p>Se adjuntan dos archivos:</p>\n"
            . "<ul><li>El XML con firma electronica, valido ante el SII.</li>\n"
            . "<li>Una representacion impresa en PDF.</li></ul>\n"
            . "<p>Si necesita responder, puede hacerlo directamente a este correo.</p>\n",
            htmlspecialchars($etiquetaTipo, ENT_QUOTES, 'UTF-8'),
            $folio,
            htmlspecialchars($nombreVisible, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($rutEmisor, ENT_QUOTES, 'UTF-8'),
            $lineaVencimiento,
            $bloquePago
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
    /**
     * El bloque HTML del link de pago, o cadena vacia si no corresponde.
     *
     * ESTATICO Y PURO A PROPOSITO. preparar() necesita un PDO y genera PDF, asi
     * que probarlo entero es caro; esto es lo unico del correo que lleva un dato
     * venido de FUERA DE CASA -- una URL que devolvio un tercero -- y va dentro
     * de un href que va a leer un cliente de nuestro cliente. Separado, se puede
     * probar cada guarda por su cuenta y sin base de datos.
     *
     * LAS CUATRO CONDICIONES, Y NINGUNA SOBRA:
     *
     *   1. El tipo se cobra (33 o 34). Segunda linea de defensa; la primera es
     *      el resolutor, que ni crea la orden.
     *   2. estado === 'creado'. Ni 'pendiente' (la orden todavia no existe alla),
     *      ni 'error', ni 'omitido' (una persona decidio soltar el correo sin
     *      link), ni 'pagado' -- a quien ya pago no se le vuelve a ofrecer pagar.
     *   3. La url empieza por https://. OrdenPagoCreada ya lo exige al construirse;
     *      se repite aqui porque entre aquello y esto hay un viaje por la base, y
     *      este es el ultimo punto antes de que lo vea un tercero. Dos cinturones
     *      para el unico dato del correo que no es nuestro.
     *   4. Hay monto. Sin el, el correo diria "paga" sin decir cuanto.
     *
     * EL MONTO SE ESCRIBE $1.234.567, con punto de miles y sin decimales. Misma
     * razon que la fecha d-m-Y de mas arriba: esto lo lee un tercero en Chile, no
     * el operador del panel.
     *
     * LA FRASE DEL FINAL no es relleno. Mientras no exista la conciliacion, un
     * link sigue vivo aunque el cliente ya haya pagado por transferencia; hay que
     * decirselo, o alguien va a pagar dos veces.
     */
    public static function bloqueLinkPago(
        ?string $url,
        ?string $estado,
        ?int $monto,
        int $tipoDte,
        int $folio = 0,
        string $etiquetaTipo = '',
        ?string $ambiente = null
    ): string {
        if (! in_array($tipoDte, self::TIPOS_CON_LINK_PAGO, true)) {
            return '';
        }
        if ($estado !== 'creado') {
            return '';
        }
        $url = trim((string) $url);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return '';
        }
        if ($monto === null || $monto <= 0) {
            return '';
        }

        $urlSegura = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $montoTxt  = '$' . number_format($monto, 0, ',', '.');

        // "Factura electronica N&deg; 3". Si por lo que sea no llegan el tipo en
        // palabras o el folio, la linea entera se cae en vez de quedar coja
        // ("N&deg; 0" o un numero sin sustantivo delante).
        $referencia = '';
        if ($etiquetaTipo !== '' && $folio > 0) {
            $referencia = sprintf(
                '<div style="%s">%s N&deg; %d</div>',
                'font:400 13px/1.4 Arial,Helvetica,sans-serif;color:#5c6470;'
                . 'padding:0 0 6px;',
                htmlspecialchars($etiquetaTipo, ENT_QUOTES, 'UTF-8'),
                $folio
            );
        }

        // EL AVISO DE PRUEBA VA ARRIBA DEL RECUADRO Y NO DENTRO, para que se lea
        // antes que el monto y el boton. Solo aparece en sandbox: en produccion
        // seria una mentira que le quitaria valor al aviso cuando de verdad haga
        // falta. Y solo lo enciende la columna de la migracion 053; ver el SELECT.
        $avisoPrueba = '';
        if (self::esSandbox($ambiente)) {
            $avisoPrueba = sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="border-collapse:collapse;margin:0 0 12px;"><tr>'
                . '<td style="%s">%s</td></tr></table>' . "\n",
                'background:#fff3cd;border:2px solid #d39e00;border-radius:6px;'
                . 'padding:12px 16px;font:700 14px/1.45 Arial,Helvetica,sans-serif;'
                . 'color:#6b4e00;text-align:center;',
                'PRUEBA &mdash; Este enlace usa Flow Sandbox y no realizar&aacute; un cobro real.'
            );
        }

        // LAS TILDES VAN COMO ENTIDAD HTML (&iacute;, &oacute;, &aacute;), no como
        // byte UTF-8. Es la convencion que ya seguia el cuerpo del correo con
        // &deg;: el archivo se queda en ASCII y el texto le llega al lector
        // acentuado, sin depender de que cada pasarela de correo respete el
        // charset. Un test comprueba que no se cuelen tildes crudas.
        //
        // POR QUE TABLAS Y style= EN CADA ETIQUETA. El correo se manda sin <head>
        // (BrevoMailer solo pone htmlContent), asi que no hay donde colgar una
        // hoja de estilos, y Outlook para escritorio renderiza con Word: ignora
        // padding y background sobre un <a>, y descuadra los div flotados. Una
        // tabla con el color en el <td> y el <a> dentro es lo que se ve igual en
        // Outlook, Gmail y iOS. role="presentation" para que un lector de
        // pantalla no la anuncie como tabla de datos.
        return $avisoPrueba . sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:18px 0;"><tr>'
            . '<td style="%s">' . "\n"
            . '<div style="%s">Paga tu factura en l&iacute;nea</div>' . "\n"
            . '%s'
            . '<div style="%s">%s</div>' . "\n"
            // El boton, en su propia tabla de una celda: es la forma que Outlook
            // respeta. El color va en el <td> Y en el <a> -- si uno de los dos se
            // pierde, el boton sigue siendo un boton y no un texto invisible.
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:separate;margin:4px 0 14px;"><tr>'
            . '<td align="center" style="%s"><a href="%s" style="%s">Pagar factura</a></td>'
            . '</tr></table>' . "\n"
            . '<div style="%s">El pago se realiza de forma segura a trav&eacute;s de Flow.</div>' . "\n"
            . '<div style="%s">Si ya la pag&oacute; por otro medio, ignore este mensaje.</div>' . "\n"
            // EL LINK EN TEXTO NO ES REDUNDANCIA. Hay clientes de correo y filtros
            // corporativos que quitan o reescriben los botones; sin esta linea,
            // quien se queda sin boton se queda sin forma de pagar. word-break
            // porque una url de pasarela es larga y en movil desborda la pantalla.
            . '<div style="%s">Si el bot&oacute;n no funciona, copia y pega esta direcci&oacute;n:</div>' . "\n"
            . '<div style="%s">%s</div>' . "\n"
            . '</td></tr></table>' . "\n",
            // recuadro
            'background:#f4f7fb;border:1px solid #d6dee8;border-left:4px solid #1f6feb;'
            . 'border-radius:6px;padding:20px 24px;',
            // titulo
            'font:700 17px/1.3 Arial,Helvetica,sans-serif;color:#16324f;padding:0 0 4px;',
            $referencia,
            // monto
            'font:700 30px/1.15 Arial,Helvetica,sans-serif;color:#16324f;padding:0 0 14px;',
            $montoTxt,
            // celda del boton
            'background:#1f6feb;border-radius:6px;',
            $urlSegura,
            // el <a> del boton
            'display:inline-block;padding:15px 34px;background:#1f6feb;color:#ffffff;'
            . 'font:700 17px/1 Arial,Helvetica,sans-serif;text-decoration:none;'
            . 'border-radius:6px;',
            // texto de apoyo
            'font:400 13px/1.5 Arial,Helvetica,sans-serif;color:#3d4a5c;padding:0 0 4px;',
            // doble pago
            'font:400 13px/1.5 Arial,Helvetica,sans-serif;color:#5c6470;padding:0 0 12px;',
            // rotulo del link de respaldo
            'font:400 12px/1.5 Arial,Helvetica,sans-serif;color:#5c6470;padding:0 0 2px;',
            // la url
            'font:400 12px/1.5 Arial,Helvetica,sans-serif;color:#1f6feb;'
            . 'word-break:break-all;',
            $urlSegura
        );
    }

    /**
     * True solo si la empresa tiene la pasarela en sandbox.
     *
     * FALLA HACIA "PRODUCCION" A PROPOSITO, que es lo contrario de lo habitual en
     * este proyecto. Un null o un valor raro aqui NO pinta el aviso de prueba: el
     * aviso dice "no se te va a cobrar", y decirlo sobre un cobro real es el unico
     * error de los dos que le cuesta dinero a alguien. Quien tiene una orden
     * creada tiene su ambiente congelado en dte_pago_link, asi que el null no es un caso
     * normal sino un dato roto, y ante un dato roto se avisa de menos.
     */
    private static function esSandbox(?string $ambiente): bool
    {
        return strtolower(trim((string) $ambiente)) === 'sandbox';
    }

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
