<?php
/*
 * PEGAR LOS DATOS DEL CLIENTE (js/pegar-receptor.js): el texto que manda el
 * cliente se reparte en los campos del receptor.
 *
 * Lo usan emision-form y cotizacion-form. Va FUERA del <form> de la vista: el
 * textarea no tiene que viajar en el POST, y Enter dentro no envia nada.
 *
 * Variable opcional antes del require:
 *   $pegarCampos  'rut:id,razonSocial:id,giro:id,direccion:id,comuna:id,email:id'
 *                 cuando los ids no son los del formulario de emision.
 * La vista pone ademas el boton [data-abrir-pegar-receptor] (oculto) y la
 * region viva #pegar-receptor-aviso.
 */
$pegarCampos = $pegarCampos ?? '';
?>
<dialog class="dialogo-confirmar dialogo-pegar" id="pegar-receptor"<?= $pegarCampos !== '' ? ' data-campos="' . htmlspecialchars($pegarCampos) . '"' : ''; ?> aria-labelledby="pegar-receptor-titulo" aria-describedby="pegar-receptor-sub">
    <h2 class="dialogo-confirmar__titulo" id="pegar-receptor-titulo">Pegar datos del cliente</h2>
    <p id="pegar-receptor-sub">Pega el texto que te mando el cliente (RUT, razon social, giro, direccion, comuna, correo). Los datos se reparten solos en sus campos; revisalos antes de seguir.</p>
    <div class="form-compacto">
        <label for="pegar-receptor-texto" class="visualmente-oculto">Texto con los datos del cliente</label>
        <textarea id="pegar-receptor-texto" data-pegar-texto rows="9" spellcheck="false" placeholder="RUT: 76.123.456-7&#10;Razon social: Comercial Los Andes SpA&#10;Giro: Venta al por mayor&#10;Direccion: Av. Providencia 1234&#10;Comuna: Providencia&#10;Correo: facturas@losandes.cl"></textarea>
    </div>
    <p class="form-ayuda" data-pegar-estado role="status" aria-live="polite"></p>
    <div class="acciones-formulario">
        <button type="button" class="boton-secundario" data-pegar-cerrar>Cancelar</button>
        <button type="button" class="boton-principal" data-pegar-aplicar>Rellenar datos</button>
    </div>
</dialog>
<?php $jsPegarVersion = @filemtime(__DIR__ . '/../../public/js/pegar-receptor.js'); ?>
<script src="/js/pegar-receptor.js<?= $jsPegarVersion ? '?v=' . $jsPegarVersion : ''; ?>" defer></script>
