<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Mi perfil</h2>
    <div class="card">
        <p><strong>Nombre:</strong> Juan Pérez</p>
        <p><strong>Email:</strong> juan@correo.com</p>
        <p><strong>Rol:</strong> Estudiante</p>
        <a class="btn" href="#">Editar perfil</a>
        <a class="btn btn-secondary" href="#">Cambiar contraseña</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>