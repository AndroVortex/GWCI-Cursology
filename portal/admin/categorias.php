<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Categorías</h2>
    <div class="card">
        <form method="post">
            <label>Nombre</label>
            <input type="text" name="nombre" required>
            <label>Descripción</label>
            <textarea name="descripcion" rows="3"></textarea>
            <p><button class="btn" type="submit">Agregar categoría</button></p>
        </form>
    </div>
    <div class="card">
        <table>
            <tr><th>Nombre</th><th>Descripción</th><th>Creado por</th><th>Fecha</th><th>Acciones</th></tr>
            <tr><td>IT &amp; Software</td><td>Cursos de programación</td><td>admin</td><td>01 Sep 2026</td>
                <td><a class="btn" href="#">Editar</a> <a class="btn btn-danger" href="#">Eliminar</a></td></tr>
            <tr><td>Marketing</td><td>Cursos de mercadotecnia</td><td>admin</td><td>01 Sep 2026</td>
                <td><a class="btn" href="#">Editar</a> <a class="btn btn-danger" href="#">Eliminar</a></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>