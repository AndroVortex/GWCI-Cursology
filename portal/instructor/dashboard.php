<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Panel del instructor</h2>
    <div class="card">
        <h3>Resumen</h3>
        <p>Cursos publicados: 2</p>
        <p>Alumnos inscritos: 45</p>
        <p>Ingresos totales: $18,500.00 MXN</p>
    </div>
    <div class="card">
        <a class="btn" href="crear-curso.php">Crear curso</a>
        <a class="btn" href="mis-cursos.php">Mis cursos</a>
        <a class="btn" href="ventas.php">Ver ventas</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>