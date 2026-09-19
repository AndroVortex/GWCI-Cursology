<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Buscar cursos';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main>
    <h2>Búsqueda de cursos</h2>
    <div class="card">
        <form method="get" action="busqueda.php">
            <label>Categoría</label>
            <select name="categoria">
                <option value="">Todas</option>
                <option value="1">IT &amp; Software</option>
                <option value="2">Marketing</option>
                <option value="3">Design</option>
            </select>

            <label>Título del curso</label>
            <input type="text" name="titulo" placeholder="Ej. PHP">

            <label>Usuario que lo publicó</label>
            <input type="text" name="instructor" placeholder="Ej. ana_dev">

            <label>Fecha inicio</label>
            <input type="date" name="fecha_inicio">
            <label>Fecha fin</label>
            <input type="date" name="fecha_fin">

            <p><button class="btn" type="submit">Buscar</button></p>
        </form>
    </div>

    <div class="card">
        <h3>Resultados</h3>
        <table>
            <tr><th>Título</th><th>Categoría</th><th>Instructor</th><th>Fecha</th><th></th></tr>
            <tr><td>PHP desde cero</td><td>IT &amp; Software</td><td>ana_dev</td><td>10 Sep 2026</td><td><a class="btn" href="detalle-curso.php?id=1">Ver</a></td></tr>
            <tr><td>PHP avanzado</td><td>IT &amp; Software</td><td>ana_dev</td><td>12 Sep 2026</td><td><a class="btn" href="detalle-curso.php?id=5">Ver</a></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>