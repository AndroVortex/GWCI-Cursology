<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Kardex</h2>
    <div class="card">
        <form method="get">
            <label>Rango de fechas de inscripción</label>
            <input type="date" name="fi"> <input type="date" name="ff">
            <label>Categoría</label>
            <select name="categoria"><option>Todas</option><option>IT &amp; Software</option></select>
            <label>Estado</label>
            <select name="estado"><option>Todos</option><option>Terminados</option><option>Activos</option></select>
            <p><button class="btn" type="submit">Filtrar</button></p>
        </form>
    </div>
    <div class="card">
        <table>
            <tr><th>Curso</th><th>Inscripción</th><th>Último acceso</th><th>Terminación</th><th>Avance</th><th>Estado</th></tr>
            <tr><td>PHP desde cero</td><td>10 Sep 2026</td><td>18 Sep 2026</td><td>—</td><td>3/5</td><td>Activo</td></tr>
            <tr><td>MySQL práctico</td><td>01 Sep 2026</td><td>15 Sep 2026</td><td>15 Sep 2026</td><td>5/5</td><td>Completo</td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>