<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Usuarios</h2>
    <div class="card">
        <table>
            <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
            <tr><td>ana_dev</td><td>Ana Dev</td><td>Instructor</td><td>Activo</td>
                <td><a class="btn btn-danger" href="#">Bloquear</a></td></tr>
            <tr><td>juan_perez</td><td>Juan Pérez</td><td>Estudiante</td><td>Bloqueado</td>
                <td><a class="btn" href="#">Desbloquear</a></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>