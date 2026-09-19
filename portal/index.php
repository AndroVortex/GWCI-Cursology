<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Inicio';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main>
    <h2>Cursos en línea</h2>

    <div class="card">
        <h3>Categorías</h3>
        <p>
            <a class="btn" href="busqueda.php?categoria=1">IT &amp; Software</a>
            <a class="btn" href="busqueda.php?categoria=2">Marketing</a>
            <a class="btn" href="busqueda.php?categoria=3">Design</a>
        </p>
    </div>

    <div class="card">
        <h3>Mejor calificados</h3>
        <table>
            <tr><th>Curso</th><th>Categoría</th><th>Instructor</th><th>Calificación</th><th></th></tr>
            <tr><td>PHP desde cero</td><td>IT &amp; Software</td><td>ana_dev</td><td>4.8</td><td><a class="btn" href="detalle-curso.php?id=1">Ver</a></td></tr>
            <tr><td>UX para principiantes</td><td>Design</td><td>luis_ux</td><td>4.7</td><td><a class="btn" href="detalle-curso.php?id=2">Ver</a></td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Más vendidos</h3>
        <table>
            <tr><th>Curso</th><th>Categoría</th><th>Instructor</th><th>Ventas</th><th></th></tr>
            <tr><td>MySQL práctico</td><td>IT &amp; Software</td><td>ana_dev</td><td>120</td><td><a class="btn" href="detalle-curso.php?id=3">Ver</a></td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Más recientes</h3>
        <table>
            <tr><th>Curso</th><th>Categoría</th><th>Instructor</th><th>Fecha</th><th></th></tr>
            <tr><td>Marketing digital 2026</td><td>Marketing</td><td>carlos_mkt</td><td>15 Sep 2026</td><td><a class="btn" href="detalle-curso.php?id=4">Ver</a></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>