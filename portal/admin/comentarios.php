<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Moderación de comentarios</h2>
    <div class="card">
        <table>
            <tr><th>Curso</th><th>Usuario</th><th>Comentario</th><th>Fecha</th><th>Acciones</th></tr>
            <tr><td>PHP desde cero</td><td>juan_perez</td><td>Comentario ofensivo...</td><td>18 Sep 2026</td>
                <td><a class="btn btn-danger" href="#">Eliminar</a></td></tr>
        </table>
    </div>
    <div class="card">
        <h3>Comentarios eliminados</h3>
        <table>
            <tr><th>Curso</th><th>Usuario</th><th>Fecha eliminación</th><th>Causa</th><th>Comentario</th></tr>
            <tr><td>PHP desde cero</td><td>spam_user</td><td>17 Sep 2026</td><td>Spam</td><td><em>Comentario borrado</em></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>