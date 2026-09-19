<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Panel del estudiante</h2>
    <div class="card">
        <h3>Resumen</h3>
        <p>Cursos inscritos: 3</p>
        <p>Cursos terminados: 1</p>
        <p>Certificados obtenidos: 1</p>
    </div>
    <div class="card">
        <h3>Acciones rápidas</h3>
        <a class="btn" href="../busqueda.php">Buscar cursos</a>
        <a class="btn" href="kardex.php">Ver Kardex</a>
        <a class="btn" href="mensajes.php">Mensajes</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>