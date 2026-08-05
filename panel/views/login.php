<?php
/**
 * Pantalla de acceso.
 *
 * Recibe: $error (string|null) y $email (string). Las pone el router en el GET
 * (/login, ambas vacias) y handleLoginPost() tras un intento fallido.
 *
 * EL CONTRATO DEL FORMULARIO NO CAMBIA. handleLoginPost() lee exactamente
 * $_POST['email'] y $_POST['password'], y el router verifica el CSRF antes de
 * llegar al handler, asi que se conservan textuales: action, method, csrfInput(),
 * los dos name, los type y los required. Al markup se agregaron solo dos cosas,
 * ninguna de las cuales altera lo que viaja en el POST:
 *   - id, para que cada <label> use for= en vez de envolver el input;
 *   - autocomplete, para que los gestores de credenciales del navegador
 *     reconozcan los campos (email / current-password son los valores del
 *     estandar HTML para una pantalla de inicio de sesion).
 *
 * LA CONTRASENA NUNCA SE RE-RENDERIZA. El input password no lleva value ni
 * despues de un error -- igual que antes --, no aparece en ningun atributo y
 * esta pantalla no tiene JavaScript.
 *
 * TODOS LOS <svg> LLEVAN width Y height COMO ATRIBUTO, ademas de la regla CSS.
 * No es redundancia: un <svg> con viewBox pero SIN dimensiones intrinsecas se
 * estira hasta llenar su contenedor cuando el CSS no llega. Medido: sin hoja de
 * estilos estos mismos iconos renderizaban a 1409x1409 px en un viewport de
 * 1440 -- que es como se vieron en produccion la primera vez. Con el atributo,
 * el peor caso posible es un icono de 20px; el CSS solo lo afina.
 *
 * La <img> de la zona visual lleva width y height por la misma razon, y ademas
 * reserva el espacio antes de que la imagen cargue, para que el bloque no salte
 * al terminar la descarga (CLS).
 *
 * NO HAY ENLACE A /registro. Es una decision de producto: el alta de tenants no
 * es autoregistro publico. La RUTA sigue existiendo y funcionando igual por URL
 * directa; aqui solo deja de ofrecerse.
 *
 * LO QUE LA MAQUETA MUESTRA Y AQUI NO ESTA, porque no existe en el sistema:
 *   - "Olvidaste tu contrasena?": no hay ninguna ruta ni flujo de recuperacion
 *     en el proyecto (verificado en el router y en las vistas). Un enlace a una
 *     pagina inexistente seria peor que no ofrecerlo.
 *   - El ojo de "mostrar contrasena": exigiria JavaScript nuevo en la unica
 *     pantalla que maneja credenciales. Se deja fuera a proposito.
 *
 * $bodyClase la lee partials/header.php: anula el max-width global del <body>
 * para que esta pantalla ocupe el ancho completo.
 */
$titulo    = 'Iniciar sesion';
$bodyClase = 'auth-page';
require __DIR__ . '/partials/header.php';

// Version del archivo, para invalidar la cache del navegador tras un
// despliegue. Mismo criterio que /css/style.css en partials/header.php: una URL
// fija deja servida la version anterior desde la cache o desde un proxy. Si el
// archivo no se puede leer se cae a la URL sin parametro.
$fondoRuta    = __DIR__ . '/../public/img/fondo.jpg';
$fondoVersion = @filemtime($fondoRuta);
$fondoSrc     = '/img/fondo.jpg' . ($fondoVersion ? '?v=' . $fondoVersion : '');

// GIF transparente de 1x1 px, en linea. Ver el bloque de la zona decorativa:
// es lo que el navegador carga en lugar de la foto cuando la decoracion esta
// oculta. Al ir embebido no genera ninguna peticion de red.
$pixelVacio = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
?>

<div class="auth-layout">

    <header class="auth-header">
        <a class="auth-marca" href="/login" aria-label="Sinergia Facturacion">
            <img src="/img/logo.png" alt="Sinergia Facturacion" class="auth-marca__logo">
        </a>
        <?php
            /* Describe lo que es la pantalla (un area que pide credenciales), no
               una certificacion ni una promesa de cifrado: el panel corre hoy
               sobre HTTP en LAN y no se puede respaldar "plataforma segura". */
        ?>
        <div class="auth-header__acciones">
            <p class="auth-header__nota">
                <svg class="auth-icono" width="20" height="20" viewBox="0 0 24 24"
                     aria-hidden="true" focusable="false">
                    <path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3z"
                          fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                    <path d="m9 12 2 2 4-4" fill="none" stroke="currentColor"
                          stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Acceso privado
            </p>
            <?php
                /* SECUNDARIO Y NO PRINCIPAL: en esta pantalla la accion primaria
                   es "Entrar", y un boton solido aqui competiria con ella. El
                   outline usa --color-primario igual, asi que va en la paleta
                   sin agregar ni un color nuevo.

                   ABRE EN PESTANA NUEVA porque planes.html es una pagina suelta
                   SIN navegacion de vuelta -- sus unicos enlaces son anclas
                   #contacto. En la misma pestana, quien la abre desde el login
                   queda sin forma de volver salvo el boton del navegador. */
            ?>
            <a class="boton-secundario auth-header__planes" href="/planes.html"
               target="_blank" rel="noopener">Ver planes</a>
        </div>
    </header>

    <div class="auth-main">

        <?php
            /* Zona decorativa. Reemplaza al SVG abstracto que habia antes. El
               CSS no cambia: .auth-visual__panel ya estaba escrito con
               width:100% y height:auto, y funciona igual para una <img>.

               QUE MUESTRA: un mockup de laptop con un dashboard generico. No hay
               ningun dato real -- ni RUT, ni folios, ni razones sociales, ni
               montos de clientes -- y el area de la pantalla va DESENFOCADA a
               proposito: el mockup traia texto generado que no formaba palabras
               reales, y difuminarlo evita mostrar algo que parece informacion
               sin serlo. Queda la composicion: el grafico, la dona y la barra de
               navegacion con la marca.

               aria-hidden y alt vacio porque no aporta informacion, solo
               contexto visual: un lector de pantalla debe saltarla.

               POR QUE <picture> Y NO UNA <img> SUELTA. Bajo 1024px la regla
               .auth-visual { display:none } retira la decoracion. Un display:none
               en el elemento PADRE no impide la descarga: el preload scanner
               encuentra el src en el HTML antes de que el CSS se aplique. Medido
               con cache-buster para descartar la cache: con una <img> suelta, un
               viewport de 768px transferia igual los 34 KB de la foto.

               Con <picture>, el primer <source> gana en pantallas de hasta
               1024px y apunta a un GIF de 1x1 embebido, asi que el navegador ni
               siquiera pide la foto. Medido en las mismas condiciones: cero
               peticiones a fondo.jpg bajo 1024px, y descarga normal por encima.
               El breakpoint de los <source> es el mismo del CSS a proposito: si
               se cambia uno hay que cambiar el otro. */
        ?>
        <div class="auth-visual" aria-hidden="true">
            <div class="auth-visual__fondo"></div>
            <picture>
                <source media="(max-width: 1024px)" srcset="<?= $pixelVacio; ?>">
                <source media="(min-width: 1025px)" srcset="<?= htmlspecialchars($fondoSrc); ?>">
                <img class="auth-visual__panel" src="<?= htmlspecialchars($fondoSrc); ?>"
                     width="1120" height="739" alt="" decoding="async">
            </picture>
        </div>

        <section class="auth-card" aria-labelledby="titulo-login">
            <img src="/img/logo.png" alt="" class="auth-card__logo">

            <h1 id="titulo-login" class="auth-card__titulo">Iniciar sesion</h1>
            <p class="auth-card__bajada">Accede a tu plataforma de facturacion Sinergia</p>

            <?php if (! empty($error)): ?>
                <?php
                    /* Se imprime el mensaje TEXTUAL que manda handleLoginPost(), que es
                       generico a proposito: no revela si el email existe. role="alert"
                       hace que un lector de pantalla lo anuncie al re-renderizar.
                       El comentario va en PHP y no en HTML para no repetir el texto del
                       error en el codigo fuente que llega al navegador. */
                ?>
                <p class="alerta alerta--error auth-alerta" role="alert">
                    <span class="alerta__icono" aria-hidden="true">&#9888;</span>
                    <span><?= htmlspecialchars($error); ?></span>
                </p>
            <?php endif; ?>

            <form method="post" action="/login" class="auth-form">
                <?= csrfInput(); ?>

                <div class="auth-campo">
                    <label for="login-email">Email</label>
                    <div class="auth-input">
                        <svg class="auth-input__icono" width="20" height="20" viewBox="0 0 24 24"
                             aria-hidden="true" focusable="false">
                            <rect x="3" y="5" width="18" height="14" rx="2.5" fill="none"
                                  stroke="currentColor" stroke-width="1.6"/>
                            <path d="m4 7 8 5.5L20 7" fill="none" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <input type="email" name="email" id="login-email" autocomplete="email"
                               value="<?= htmlspecialchars($email); ?>" required>
                    </div>
                </div>

                <div class="auth-campo">
                    <label for="login-password">Contrasena</label>
                    <div class="auth-input">
                        <svg class="auth-input__icono" width="20" height="20" viewBox="0 0 24 24"
                             aria-hidden="true" focusable="false">
                            <rect x="4.5" y="10" width="15" height="10" rx="2.5" fill="none"
                                  stroke="currentColor" stroke-width="1.6"/>
                            <path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        <input type="password" name="password" id="login-password"
                               autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" class="auth-boton">Entrar</button>
            </form>
        </section>
    </div>

    <footer class="auth-footer">
        <p class="auth-footer__marca">Sinergia Facturacion</p>
        <?php
            /* Unico hecho que se afirma: lo que el sistema hace. Sin SLA, sin
               disponibilidad prometida y sin estandares de seguridad. */
        ?>
        <p class="auth-footer__nota">Emision de documentos tributarios electronicos ante el SII</p>
        <p class="auth-footer__legal">&copy; <?= date('Y'); ?> Sinergia</p>
    </footer>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
