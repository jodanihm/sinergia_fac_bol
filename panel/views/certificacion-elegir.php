<?php
/**
 * Certificacion: elegir entre Factura y Boleta. Son dos procesos
 * independientes ante el SII; van LADO A LADO para comparar de un vistazo
 * cuanto le falta a cada uno.
 *
 * Los segmentos ($segmentosFactura / $segmentosBoleta) llegan ya calculados
 * del controlador, con 'nombre' y 'clase' (etapa--completada | --activa |
 * --no-gestionada | --rechazada). Se pintan con el mismo componente "Por pasos"
 * que la barra de la certificacion de factura, sin enlace por etapa.
 */
$titulo = 'Certificacion';
require __DIR__ . '/partials/header.php';

$procesos = [
    ['nombre' => 'Factura', 'listas' => $completadasFactura, 'texto' => $textoFactura, 'segmentos' => $segmentosFactura, 'destino' => '/certificacion'],
    ['nombre' => 'Boleta',  'listas' => $completadasBoleta,  'texto' => $textoBoleta,  'segmentos' => $segmentosBoleta,  'destino' => '/certificacion/boleta'],
];
?>

<div class="dash-header">
    <div>
        <h1>Certificacion</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Factura y Boleta se certifican por separado ante el SII, cada una con sus 6 etapas.
</p>

<div class="config-columnas config-columnas--2">
    <?php foreach ($procesos as $p): ?>
        <section class="tarjeta" aria-labelledby="titulo-<?= strtolower($p['nombre']); ?>">
            <h2 id="titulo-<?= strtolower($p['nombre']); ?>"><?= $p['nombre']; ?></h2>
            <p class="tarjeta__intro"><?= htmlspecialchars($p['texto']); ?></p>
            <?php
                $barraSegmentos = $p['segmentos'];
                $barraListas    = (int) $p['listas'];
                $barraToca      = null;
                foreach ($p['segmentos'] as $s) {
                    if ($s['clase'] === 'etapa--activa') {
                        $barraToca = $s['nombre'];
                        break;
                    }
                }
                $barraEnlazada  = false;
                $barraEtiqueta  = 'Etapas de la certificacion de ' . strtolower($p['nombre']);
                require __DIR__ . '/partials/certificacion/_lista-etapas.php';
            ?>
            <a class="boton-secundario" href="<?= $p['destino']; ?>">Ir a certificacion de <?= strtolower($p['nombre']); ?></a>
        </section>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
