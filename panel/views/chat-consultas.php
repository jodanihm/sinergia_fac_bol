<?php
/**
 * Chat de consultas: una pregunta en palabras, una respuesta con numeros.
 *
 * Recibe: $pregunta, $resultado, $aviso, $usadas, $limite, $recientes, $navActivo.
 *
 *   $resultado  null, o ['descripcion' => string, 'meta' => array, 'filas' => list]
 *   $hilo       lista de turnos: ['rol'=>'usuario'|'asistente', 'texto'=>string,
 *               'tipo'=>string, 'resultado'=>?array]. Solo el ultimo trae tabla.
 *   $conversacionId  identificador de ESTA pestaña (hidden); ver handleChatGet()
 *   $pendiente  null, o ['id'=>string, 'listo'=>bool, 'documentos'=>int]
 *   $flash      null, o ['tipo'=>string, 'mensaje'=>string]
 *   $recientes  list de ['pregunta', 'desenlace', 'created_at'] DE ESTA CUENTA
 *
 * LOS CUATRO DESENLACES SE VEN DISTINTO A PROPOSITO:
 *   respuesta      -> la tabla, con la descripcion de QUE se consulto encima.
 *   imposible      -> aviso 'info' con el motivo. NO es un error: "que producto
 *                     vendi mas" es una pregunta legitima sin datos detras.
 *   no entendida   -> aviso 'info' pidiendo reformular, con un ejemplo.
 *   fallo tecnico  -> aviso 'error'. Falta la clave o el proveedor no respondio.
 *
 * SIN JAVASCRIPT. Es un formulario que hace POST y repinta. Un chat con
 * streaming es otra entrega; esto tiene que funcionar y verse antes.
 */
$titulo = 'Asistente IA';
require __DIR__ . '/partials/header.php';

$monto = static fn ($n): string => '$ ' . number_format((float) $n, 0, ',', '.');

/* MAXIMO DE CARACTERES. Es el mismo que acepta chat_consulta.pregunta
   (VARCHAR(500)): si la pantalla dejara escribir mas, el historial guardaria una
   pregunta cortada y el usuario no reconoceria la suya. */
$maxPregunta = 500;

/* Las sugerencias, en un solo sitio: las usan los chips y la tarjeta lateral.
   Escritas aqui y no traidas del modelo -- son ejemplos de lo que el sistema SI
   sabe responder, y por eso salen de las agrupaciones que existen. */
$sugerencias = [
    ['icono' => 'informe-tipos',   'texto' => '¿Cuantas facturas emiti en julio?'],
    ['icono' => 'informe-clientes','texto' => '¿Cual fue mi mejor cliente del año?'],
    ['icono' => 'informe-dia',     'texto' => '¿Que mes vendi mas?'],
    ['icono' => 'informe-detalle', 'texto' => 'Detalle de facturacion de agosto'],
];
?>

<div class="dash-header">
    <div class="chat-titulo">
        <?= iconoSvg('ia', 28, 'chat-titulo__icono'); ?>
        <div>
            <h1>Asistente IA</h1>
            <?php
            /* LA SEGUNDA FRASE NO ES ADORNO. El asistente NO emite: prepara un
               borrador que la persona confirma. Decirlo ANTES de que alguien
               escriba nada evita la pregunta "¿esto ya facturo algo?", que es
               justo la duda que aparece cuando una herramienta de IA toca
               documentos tributarios.

               Se solto el "con los mismos datos de tu panel" que decia antes: lo
               dice la tarjeta lateral ("Usa los mismos datos y filtros de tu
               panel") y repetirlo aqui alargaba la linea sin agregar nada. */
            ?>
            <p class="dash-header__sub">
                Consulta tu facturacion y arma facturas conversando: una sola o varias a la
                vez. Tu revisas y emites; el asistente solo prepara el borrador.
            </p>
        </div>
    </div>
    <?php
    /* EL BADGE DICE LO QUE ESTA PROBADO Y NADA MAS. "Solo se envia tu pregunta"
       es exactamente lo que verifica testLaPeticionNoLlevaNingunDatoDelTenant():
       ni cuenta_id, ni RUT, ni cifras, ni la forma de una consulta. El matiz --
       que el TEXTO de la pregunta viaja integro -- va en la banda del pie, donde
       hay sitio para decirlo bien. */
    ?>
    <span class="chat-badge"><?= iconoSvg('escudo', 15, 'chat-badge__icono'); ?>Seguro &middot; Solo se envia tu pregunta</span>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
        <?php /* El Excel se ofrece PEGADO al mensaje que lo anuncia, no en un menu
                 aparte: es el unico momento en que el usuario sabe que existe. */ ?>
        <?php if (($flash['tipo'] ?? '') === 'exito' && str_contains((string) $flash['mensaje'], 'Excel')): ?>
            <a class="boton-secundario" href="/chat/excel">Bajar el Excel</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="chat-layout">
    <div class="chat-principal">

        <?php
        /* LA BIENVENIDA SOLO CUANDO NO HAY CONVERSACION. Encima de diez turnos ya
           dice lo que el usuario averiguo hace rato, y empuja el hilo fuera de la
           pantalla justo cuando mas importa verlo. */
        ?>
        <?php if ($hilo === []): ?>
        <section class="tarjeta chat-bienvenida">
            <?= iconoSvg('robot', 34, 'chat-bienvenida__avatar'); ?>
            <div>
                <h2>Hola, soy tu Asistente IA de SinergIA</h2>
                <p>
                    Estoy aqui para ayudarte a entender tu facturacion. Hazme preguntas sobre
                    tus ventas, clientes, documentos y mas.
                </p>
            </div>
        </section>
        <?php endif; ?>

<?php
/* ===================================================================
   EL HILO. Turnos diferenciados, no una respuesta flotando abajo.
   ===================================================================
   EL DEFECTO QUE ARREGLA, REPORTADO POR DANIEL: con una sola pregunta y una sola
   respuesta la falta de estructura pasaba desapercibida; con una conversacion de
   varios turnos, encontrar la continuacion se volvio adivinanza -- y le costo
   hasta a quien la diseño.

   LAS TABLAS NO VAN DENTRO DEL GLOBO. Una consulta puede devolver 100 filas, y
   eso metido en una burbuja de 75% de ancho es peor que lo de antes. La burbuja
   lleva la frase; la tabla se dibuja debajo, a ancho completo, atada a su turno.

   SOLO EL ULTIMO TURNO CONSERVA SU TABLA (ver chatHiloAgregar): los anteriores
   guardan su frase, que es lo que se relee al desplazarse hacia arriba. */
?>
<?php foreach ($hilo as $i => $turno): ?>
    <div class="chat-turno chat-turno--<?= $turno['rol'] === 'usuario' ? 'usuario' : 'asistente'; ?>">
        <div class="chat-burbuja">
            <?= nl2br(htmlspecialchars((string) $turno['texto'])); ?>
        </div>
    </div>

    <?php $resultado = $turno['resultado'] ?? null; ?>
    <?php if (is_array($resultado)): ?>
    <section class="tarjeta chat-resultado">
        <?php
        /* QUE ENTENDI, EN PALABRAS. Va ARRIBA de los numeros y no debajo: es lo
           que le permite al usuario darse cuenta de que la pregunta se
           interpreto mal ANTES de creerse la cifra. Sin esto, un numero que
           contesta otra pregunta pasa por bueno. */
        ?>
        <p class="nota">
            <strong>Consulte:</strong> <?= htmlspecialchars((string) $resultado['descripcion']); ?>.
        </p>

        <?php if ($resultado['filas'] === []): ?>
            <p class="vacio">
                No hay documentos que cumplan eso en ese periodo.
                <?php if (! empty($resultado['meta']['sinDatos'])): ?>
                    (<?= htmlspecialchars((string) $resultado['meta']['sinDatos']); ?>)
                <?php endif; ?>
            </p>
        <?php elseif ($resultado['meta']['agruparPor'] === 'documento'): ?>
            <?php
            /* EL LISTADO. Otra tabla, porque la fila es otra cosa: aqui cada
               linea es UN documento, no un grupo. Las claves de mas (folio,
               fecha, tipo, rut) solo vienen en esta agrupacion. */
            ?>
            <div class="tabla-scroll">
                <table class="tabla-datos">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Cliente</th>
                            <th class="tabla-datos__num">Neto</th>
                            <th class="tabla-datos__num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['filas'] as $f): ?>
                            <tr>
                                <td><?= (int) $f['folio']; ?></td>
                                <td><?= htmlspecialchars(date('d-m-Y', (int) strtotime((string) $f['fecha']))); ?></td>
                                <td><?= htmlspecialchars((string) $f['glosaTipo']); ?></td>
                                <td>
                                    <?= htmlspecialchars((string) $f['etiqueta']); ?>
                                    <?php if ($f['etiqueta'] !== $f['rut']): ?>
                                        <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $f['rut']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tabla-datos__num"><?= $monto($f['desglose']['neto']); ?></td>
                                <td class="tabla-datos__num"><?= $monto($f['desglose']['monto']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">
                Las notas de credito aparecen en negativo: sumar esta lista da el total del periodo.
            </p>
        <?php else: ?>
            <?php $metrica = (string) $resultado['meta']['metrica']; ?>
            <div class="tabla-scroll">
                <table class="tabla-datos">
                    <thead>
                        <tr>
                            <th><?= $resultado['meta']['agruparPor'] === 'ninguna' ? 'Periodo' : 'Grupo'; ?></th>
                            <th class="tabla-datos__num">Documentos</th>
                            <th class="tabla-datos__num">Neto</th>
                            <th class="tabla-datos__num">Exento</th>
                            <th class="tabla-datos__num">Impuestos</th>
                            <th class="tabla-datos__num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['filas'] as $f): ?>
                            <?php
                            /* TODAS LAS CIFRAS, SIEMPRE. La metrica que el modelo
                               eligio solo decide cual se destaca -- asi, si
                               interpreto "monto" donde el usuario queria "neto",
                               el numero que buscaba igual esta a la vista.

                               EL ?? NO ES DEFENSA CONTRA UN CASO IMPOSIBLE: paso
                               en produccion. La vista nueva se desplego con el
                               repositorio VIEJO, que no mandaba 'desglose', y la
                               pantalla se lleno de "Undefined array key" sobre la
                               respuesta del usuario.

                               Y NO SE RELLENA CON CEROS EN SILENCIO. Un cero es
                               un dato y aqui no lo hay: se muestra un guion en lo
                               que falta, se conserva lo que SI vino (valor y
                               documentos) y se avisa debajo de la tabla. Rellenar
                               con ceros habria convertido un despliegue a medias
                               en cifras falsas que nadie revisa. */
                            $d = $f['desglose'] ?? null;
                            if ($d === null) {
                                $desgloseIncompleto = true;
                                $d = [
                                    'documentos' => $f['documentos'] ?? null,
                                    // La metrica pedida SI se conoce: es 'valor'.
                                    $metrica     => $f['valor'] ?? null,
                                ];
                            }
                            $cifra = static fn (string $k) => array_key_exists($k, $d) && $d[$k] !== null
                                ? $d[$k] : null;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $f['etiqueta']); ?></td>
                                <td class="tabla-datos__num<?= $metrica === 'documentos' ? ' tabla-datos__num--destacado' : ''; ?>">
                                    <?= $cifra('documentos') !== null ? number_format((float) $cifra('documentos'), 0, ',', '.') : '&mdash;'; ?>
                                </td>
                                <?php foreach (['neto', 'exento', 'impuesto', 'monto'] as $col): ?>
                                    <?php
                                    $destacada = $col === $metrica
                                        || ($col === 'monto' && $metrica === 'promedio');
                                    ?>
                                    <td class="tabla-datos__num<?= $destacada ? ' tabla-datos__num--destacado' : ''; ?>">
                                        <?= $cifra($col) !== null ? $monto($cifra($col)) : '&mdash;'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (! empty($desgloseIncompleto)): ?>
                <?php
                /* EL AVISO ES PARA QUE UN DESPLIEGUE A MEDIAS SE VEA. Sin el, la
                   tabla saldria con guiones y pareceria que no hay datos, cuando
                   lo que pasa es que la pantalla y la consulta estan en versiones
                   distintas. */
                ?>
                <div class="alerta alerta--advertencia" role="status">
                    Algunas cifras no llegaron completas. Es un problema del sistema, no de tu
                    pregunta: avisa a soporte y menciona esta pantalla.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (! empty($resultado['meta']['hayMas'])): ?>
            <?php
            /* SE DICE CUANDO SE RECORTO. Una lista truncada en silencio se lee
               como completa, y es peor que no mostrarla: el usuario contaria mal
               y no tendria como saberlo. El repositorio pide una fila de mas
               justamente para poder afirmar esto. */
            ?>
            <div class="alerta alerta--advertencia" role="status">
                Hay mas resultados de los que caben: se muestran los primeros
                <?= (int) $resultado['meta']['limite']; ?>. Acota el periodo o pide menos filas.
            </div>
        <?php endif; ?>

        <?php
        /* DE DONDE SALIO EL NUMERO. Mismo criterio que la formula bajo la cifra
           del dashboard: el total no es una caja negra. Y es lo que explica por
           que este numero y el del dashboard son el mismo. */
        ?>
        <p class="nota">
            <?= htmlspecialchars((string) $resultado['meta']['filtros']); ?>.
            Las notas de credito restan, y los documentos rechazados por el SII no se cuentan
            &mdash; el mismo criterio del panel.
        </p>
    </section>
    <?php endif; ?>
<?php endforeach; ?>

        <?php
        /* EL AVISO DE BORRADOR A MEDIAS, Y SU BOTON DE CONFIRMAR.
         *
         * ESTABA ARRIBA DE LA PAGINA, y ahi funcionaba mientras el cuadro de
         * escribir tambien estaba arriba. Al bajar el redactor y hacer crecer el
         * hilo, el boton se quedo donde estaba: el scroll automatico deja al
         * usuario mirando el final de la conversacion, con la accion mas
         * importante fuera de pantalla. Hubo que buscarla desplazandose.
         *
         * AHORA VA PEGADO AL REDACTOR, que es donde el usuario esta mirando y
         * escribiendo. Sin position:fixed: en el movil pelea con el teclado
         * virtual y termina tapando justo el campo.
         *
         * NO SE DUPLICA ARRIBA Y ABAJO. Dos botones "Confirmar y crear" en la
         * misma pantalla son una invitacion a confirmar dos veces, y confirmar
         * crea cliente, cotizaciones y correlativos. El flash del Excel SI se
         * queda arriba: es el resultado de algo ya hecho, no una accion pendiente.
         *
         * LAS DOS ACCIONES SON POST, no enlaces: una crea filas y la otra descarta
         * trabajo, y ninguna puede ocurrir porque un navegador precargue un
         * enlace. Por eso llevan csrfInput(), que el router valida para todo POST. */
        ?>
        <?php if (! empty($pendiente)): ?>
            <div class="alerta alerta--advertencia chat-pendiente" role="status">
                <div>
                    <strong>Tienes un borrador a medias.</strong>
                    <?php if ($pendiente['listo'] && $pendiente['documentos'] > 0): ?>
                        <?= (int) $pendiente['documentos']; ?>
                        factura<?= $pendiente['documentos'] === 1 ? '' : 's'; ?>
                        lista<?= $pendiente['documentos'] === 1 ? '' : 's'; ?> para confirmar.
                        Todavia no se ha creado nada.
                    <?php else: ?>
                        La conversacion quedo sin terminar. Sigue escribiendo para completarla.
                    <?php endif; ?>
                </div>
                <div class="acciones-grupo">
                    <?php if ($pendiente['listo'] && $pendiente['documentos'] > 0): ?>
                        <form method="post" action="/chat/confirmar">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="conversacion_id" value="<?= htmlspecialchars((string) $pendiente['id']); ?>">
                            <button type="submit" class="boton-principal">Confirmar y crear</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/chat/descartar">
                        <?= csrfInput(); ?>
                        <input type="hidden" name="conversacion_id" value="<?= htmlspecialchars((string) $pendiente['id']); ?>">
                        <button type="submit" class="boton-texto">Descartar</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php
        /* ==============================================================
           EL CUADRO DE ESCRIBIR VA ABAJO, DESPUES DEL HILO.
           ==============================================================
           Estaba arriba, que es de donde venia -- cuando solo habia una pregunta
           y una respuesta daba igual. Con una conversacion de varios turnos se
           lee al reves de como se lee cualquier chat: uno escribe abajo y lo
           nuevo aparece encima del cuadro, no debajo de el.

           SE MUEVE EL MARCADO, NO SE REORDENA CON CSS. Un `order` de flexbox
           cambia lo que se ve pero no el orden del documento: el lector de
           pantalla y la navegacion por tabulador seguirian encontrando el campo
           antes que la conversacion, y sin CSS quedaria igual que antes. */
        ?>
        <section class="tarjeta chat-redactor">
            <form method="post" action="/chat" class="form-compacto" id="form-chat">
                <?= csrfInput(); ?>
                <?php
                /* EL IDENTIFICADOR DE ESTA PESTAÑA, no de la sesion.
                   Dos pestañas comparten cookie y sesion PHP, asi que sin esto no
                   habria forma de que una conversacion a medias en una no se
                   mezclara con la de la otra. Se genera en el GET y vuelve igual
                   en cada envio -- exactamente el patron del idem_key del
                   formulario de emision. */
                ?>
                <input type="hidden" name="conversacion_id"
                       value="<?= htmlspecialchars((string) ($conversacionId ?? '')); ?>">
                <div class="form-campo">
                    <label for="pregunta"><?= $hilo === [] ? 'Tu pregunta' : 'Sigue la conversacion'; ?></label>
                    <?php
                    /* TEXTAREA Y NO input: un pedido de factura con varios items
                       no cabe comodo en una linea, y el usuario terminaba
                       escribiendo sin ver lo que llevaba escrito.

                       EL VALOR VA COMO CONTENIDO, no en un atributo value -- un
                       textarea no tiene value --, y sin un salto de linea despues
                       de la etiqueta de apertura: el navegador se come el primero
                       y cualquier otro quedaria dentro del texto. */
                    ?>
                    <textarea name="pregunta" id="pregunta" class="chat-pregunta"
                              maxlength="<?= $maxPregunta; ?>" rows="2"
                              placeholder="cuanto le vendi a cada cliente en los ultimos 6 meses"
                              autofocus><?= htmlspecialchars((string) $pregunta); ?></textarea>
                    <?php /* El contador se rellena por JS; sin JS queda el maximo, que sigue siendo cierto. */ ?>
                    <small class="chat-contador" id="chat-contador" aria-live="polite">
                        <?= mb_strlen((string) $pregunta); ?>/<?= $maxPregunta; ?>
                    </small>
                </div>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal chat-enviar">
                        <?= iconoSvg('enviar', 16, ''); ?>Preguntar
                    </button>
                </div>
            </form>

            <?php /* Las sugerencias solo al empezar: con la conversacion andando
                     no son ayuda, son ruido entre el hilo y el cuadro. */ ?>
            <?php if ($hilo === []): ?>
            <div class="chat-sugerencias">
                <p class="chat-sugerencias__titulo"><span>O prueba con estas sugerencias</span></p>
                <div class="chat-chips">
                    <?php foreach ($sugerencias as $s): ?>
                        <?php /* type=button: RELLENAN el campo, no envian. Preguntar cuesta
                                 dinero y un clic accidental no puede gastarlo. */ ?>
                        <button type="button" class="chat-chip" data-pregunta="<?= htmlspecialchars($s['texto']); ?>">
                            <?= iconoSvg($s['icono'], 14, ''); ?><?= htmlspecialchars($s['texto']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <p class="chat-cupo">
                <?php
                /* EL CONTADOR SE MUESTRA SIEMPRE, no solo al agotarse: enterarse del
                   tope cuando ya no queda es lo que hace que un limite se sienta
                   arbitrario. El dato es real -- chat_consulta_uso de ESTA cuenta. */
                ?>
                <?= iconoSvg('reloj', 15, ''); ?>
                Llevas <strong><?= (int) $usadas; ?> de <?= (int) $limite; ?></strong> consultas de hoy.
                <span class="chat-cupo__nota">Se reinicia el contador cada dia a las 00:00 hrs.</span>
            </p>
        </section>

    </div><?php /* /chat-principal */ ?>

    <aside class="chat-lateral">
        <section class="tarjeta">
            <h2>Consultas sugeridas</h2>
            <ul class="chat-lista">
                <?php foreach ($sugerencias as $s): ?>
                    <li>
                        <button type="button" class="chat-sugerida" data-pregunta="<?= htmlspecialchars($s['texto']); ?>">
                            <span><?= htmlspecialchars($s['texto']); ?></span>
                            <?= iconoSvg('flecha', 14, 'chat-sugerida__flecha'); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="tarjeta">
            <h2>Que puede hacer</h2>
            <?php
            /* CADA LINEA TIENE QUE SER CIERTA. "Compara periodos, clientes y tipos
               de documento" y no "y productos": no hay dato de producto -- el
               detalle vive dentro del XML -- y el propio asistente responde que no
               puede. Prometerlo aqui seria contradecir a la pantalla de al lado. */
            ?>
            <ul class="chat-lista chat-lista--checks">
                <?php foreach ([
                    'Responde preguntas sobre tus ventas y facturacion',
                    'Compara periodos, clientes y tipos de documento',
                    'Entrega totales y listados en segundos',
                    'Usa los mismos datos y filtros de tu panel',
                ] as $capacidad): ?>
                    <li><?= iconoSvg('check', 15, 'chat-check'); ?><span><?= htmlspecialchars($capacidad); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="tarjeta">
            <h2>Actividad reciente</h2>
            <?php if ($recientes === []): ?>
                <p class="vacio">Todavia no has preguntado nada.</p>
            <?php else: ?>
                <ul class="chat-lista chat-lista--actividad">
                    <?php foreach ($recientes as $r): ?>
                        <li>
                            <?php /* Clicable: repetir una pregunta sin reescribirla es el motivo
                                     por el que esta tarjeta existe. Rellena, no envia. */ ?>
                            <button type="button" class="chat-sugerida" data-pregunta="<?= htmlspecialchars((string) $r['pregunta']); ?>">
                                <span><?= htmlspecialchars((string) $r['pregunta']); ?></span>
                            </button>
                            <span class="chat-actividad__fecha">
                                <?= htmlspecialchars(date('d-m-Y H:i', (int) strtotime((string) $r['created_at']))); ?>
                                <?php if ($r['desenlace'] !== 'respondida'): ?>
                                    &middot; sin respuesta
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
</div><?php /* /chat-layout */ ?>

<section class="tarjeta chat-privacidad">
    <?= iconoSvg('escudo', 22, 'chat-privacidad__icono'); ?>
    <div>
        <h2>Privacidad y seguridad</h2>
        <p>
            Solo enviamos tu pregunta al servicio externo. No se comparten tus datos, cifras
            ni el RUT de tu empresa: la respuesta se calcula aqui, con la informacion de tu
            panel.
        </p>
        <p class="nota">
            <?php
            /* EL MATIZ QUE FALTABA, Y NO SE INFLA EL RESTO. Lo verificado es que el
               SISTEMA no adjunta nada del tenant; lo que el usuario escriba viaja
               integro, y eso hay que decirlo donde hay sitio para decirlo bien. */
            ?>
            Ten en cuenta que <strong>tu pregunta se envia tal como la escribes</strong>: si
            incluyes el nombre de un cliente, ese nombre viaja en el texto.
        </p>
    </div>
</section>

<script>
(function () {
    var campo    = document.getElementById('pregunta');
    var contador = document.getElementById('chat-contador');
    var MAX      = <?= (int) $maxPregunta; ?>;

    function actualizar() {
        contador.textContent = campo.value.length + '/' + MAX;
    }
    campo.addEventListener('input', actualizar);

    // ENTER ENVIA, SHIFT+ENTER HACE SALTO DE LINEA.
    //
    // Con el <input type="text"> de antes, Enter enviaba porque lo hace el
    // navegador. Un <textarea> no: ahi Enter escribe un salto. Sin esto, el
    // cambio a multilinea se habria llevado por delante la forma de enviar que
    // todo el mundo usa en un chat, y nadie lo habria atribuido al textarea.
    campo.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey) { return; }
        e.preventDefault();
        // requestSubmit() y no submit(): dispara la validacion del formulario y
        // el evento submit, igual que apretar el boton. submit() se los salta.
        var form = document.getElementById('form-chat');
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    });

    // Los chips y la actividad reciente RELLENAN el campo y le dan el foco.
    // NUNCA envian: cada pregunta cuesta dinero y un clic no puede gastarlo.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pregunta]');
        if (!b) { return; }
        campo.value = b.getAttribute('data-pregunta');
        actualizar();
        campo.focus();
    });

    // SIN SCROLL AUTOMATICO, A PROPOSITO.
    //
    // Hubo un scrollIntoView() al ultimo turno, con su ancla #ultimo en el
    // redirect. Se quito: las conversaciones de este chat son cortas y caben
    // enteras en una pantalla, asi que saltar al final dejaba la pagina a medio
    // camino sin ganar nada -- y escondia arriba el principio de la conversacion.
    // La pagina carga como cualquier otra del panel y se lee desde el principio.
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
