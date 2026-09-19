<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Crear curso</h2>
    <div class="card">
        <form method="post">
            <label>Título</label>
            <input type="text" name="titulo" required>

            <label>Categoría</label>
            <select name="categoria">
                <option>IT &amp; Software</option>
                <option>Marketing</option>
                <option>Design</option>
            </select>

            <label>Descripción</label>
            <textarea name="descripcion" rows="4"></textarea>

            <label>Imagen</label>
            <input type="file" name="imagen">

            <label>Precio del curso completo</label>
            <input type="number" step="0.01" name="precio" value="0">

            <p><button class="btn" type="submit">Guardar curso</button></p>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>