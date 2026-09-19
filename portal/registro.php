<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Registro';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main>
    <h2>Crear cuenta</h2>
    <div class="card">
        <form method="post" action="registro.php">
            <label>Nombre completo</label>
            <input type="text" name="nombre" required>

            <label>Género</label>
            <select name="genero">
                <option value="M">Masculino</option>
                <option value="F">Femenino</option>
                <option value="O">Otro</option>
            </select>

            <label>Fecha de nacimiento</label>
            <input type="date" name="fecha_nacimiento" required>

            <label>Foto de perfil (avatar)</label>
            <input type="file" name="avatar" accept="image/*">

            <label>Email</label>
            <input type="email" name="email" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>
            <p class="msg-error">Mínimo 8 caracteres, una mayúscula, un número y un carácter especial.</p>

            <label>Rol</label>
            <select name="rol">
                <option value="estudiante">Estudiante</option>
                <option value="instructor">Instructor</option>
            </select>

            <p><button class="btn" type="submit">Registrarme</button></p>
        </form>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>