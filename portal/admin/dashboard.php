<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Panel del administrador</h2>
    <div class="card">
        <h3>Resumen</h3>
        <p>Usuarios activos: 120</p>
        <p>Cursos publicados: 25</p>
        <p>Comentarios reportados: 2</p>
    </div>
    <div class="card">
        <a class="btn" href="categorias.php">Gestionar categorías</a>
        <a class="btn" href="usuarios.php">Gestionar usuarios</a>
        <a class="btn" href="comentarios.php">Moderar comentarios</a>
        <a class="btn" href="reportes.php">Ver reportes</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>