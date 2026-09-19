<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prototipo: solo redirige según el rol seleccionado
    $rol = $_POST['rol'] ?? 'estudiante';
    $_SESSION['rol'] = $rol;
    switch ($rol) {
        case 'estudiante': header('Location: estudiante/dashboard.php'); exit;
        case 'instructor': header('Location: instructor/dashboard.php'); exit;
        case 'admin':      header('Location: admin/dashboard.php'); exit;
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main>
    <h2>Iniciar sesión</h2>
    <div class="card">
        <form method="post" action="login.php">
            <label>Email o alias</label>
            <input type="text" name="login" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>

            <label>Tipo de Usuario</label>
            <select name="rol">
                <option value="estudiante">Estudiante</option>
                <option value="instructor">Instructor</option>
                <option value="admin">Administrador</option>
            </select>

            <p><button class="btn" type="submit">Entrar</button></p>
            <p class="msg-error">Si fallas 3 veces consecutivas, la cuenta se deshabilita.</p>
        </form>
        <p>¿No tienes cuenta? <a href="registro.php">Regístrate</a></p>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>