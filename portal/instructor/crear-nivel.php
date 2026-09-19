<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Agregar nivel</h2>
    <div class="card">
        <form method="post">
            <label>Número de nivel</label>
            <input type="number" name="numero" required>

            <label>Título</label>
            <input type="text" name="titulo" required>

            <label>Descripción</label>
            <textarea name="descripcion" rows="3"></textarea>

            <label>Video (URL obligatoria)</label>
            <input type="url" name="video" required>

            <label>¿Es gratis?</label>
            <select name="es_gratis"><option value="0">No</option><option value="1">Sí</option></select>

            <label>Precio del nivel</label>
            <input type="number" step="0.01" name="precio" value="0">

            <p><button class="btn" type="submit">Guardar nivel</button></p>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>