<?php
$titulo = 'Carga masiva de notas de venta';
require __DIR__ . '/partials/header.php';
?>

<h1>Carga masiva de notas de venta</h1>

<?php if (! empty($error)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        <?= htmlspecialchars($error); ?>
    </p>
<?php endif; ?>

<p>
    Sube un Excel (.xlsx) con las notas de venta a facturar. Cada fila es una nota;
    si el receptor ya existe en tus <a href="/maestros/clientes">clientes</a> se usan
    sus datos del maestro (la razon social/giro/direccion/comuna del archivo se ignoran
    en ese caso). Si es un cliente nuevo, esos campos son obligatorios en el archivo.
</p>

<p><a href="/ventas/carga-masiva/plantilla">Descargar plantilla (.xlsx)</a></p>

<form method="post" action="/ventas/carga-masiva" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo Excel (.xlsx)
        <input type="file" name="archivo" accept=".xlsx" required>
    </label>
    <button type="submit" style="margin-top:1.25rem;">Cargar</button>
</form>

<h2 style="margin-top:2rem;">Lotes cargados</h2>

<?php if ($lotes === []): ?>
    <p>Todavia no has cargado ningun archivo.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-lotes-carga">
            <thead>
                <tr>
                    <th>Fecha</th><th>Archivo</th><th>Total filas</th>
                    <th>Validas</th><th>Con error</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lotes as $l): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $l['created_at']); ?></td>
                        <td><?= htmlspecialchars((string) $l['nombre_archivo']); ?></td>
                        <td><?= (int) $l['total_filas']; ?></td>
                        <td><?= (int) $l['filas_validas']; ?></td>
                        <td><?= (int) $l['filas_error']; ?></td>
                        <td class="acciones">
                            <a href="/ventas/carga-masiva/<?= (int) $l['id']; ?>">Ver detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p style="margin-top:1.25rem;"><a href="/ventas/facturacion-masiva">Ir a facturacion masiva &rarr;</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
