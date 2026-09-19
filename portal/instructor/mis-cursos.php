<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Mis cursos</h2>
    <div class="card">
        <table>
            <tr><th>Título</th><th>Categoría</th><th>Niveles</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr>
            <tr>
                <td>PHP desde cero</td><td>IT &amp; Software</td><td>5</td><td>$1,200</td><td>Activo</td>
                <td>
                    <a class="btn" href="crear-nivel.php?curso=1">Agregar nivel</a>
                    <a class="btn btn-danger" href="#">Dar de baja</a>
                </td>
            </tr>
            <tr>
                <td>MySQL práctico</td><td>IT &amp; Software</td><td>4</td><td>$900</td><td>Activo</td>
                <td>
                    <a class="btn" href="crear-nivel.php?curso=2">Agregar nivel</a>
                    <a class="btn btn-danger" href="#">Dar de baja</a>
                </td>
            </tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>