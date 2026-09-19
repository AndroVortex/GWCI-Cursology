<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Mi perfil (Instructor)</h2>
    <div class="card">
        <p><strong>Nombre:</strong> Ana Dev</p>
        <p><strong>Email:</strong> ana@correo.com</p>
        <p><strong>Rol:</strong> Instructor</p>
        <a class="btn" href="#">Editar perfil</a>
        <a class="btn btn-secondary" href="#">Cambiar contraseña</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>