<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Ventas</h2>

    <div class="card">
        <h3>Vista 1 — Resumen por curso</h3>
        <table>
            <tr><th>Curso</th><th>Alumnos</th><th>Nivel promedio</th><th>Ingresos</th></tr>
            <tr><td>PHP desde cero</td><td>30</td><td>3</td><td>$12,000.00</td></tr>
            <tr><td>MySQL práctico</td><td>15</td><td>2</td><td>$6,500.00</td></tr>
            <tr><th colspan="3">Total</th><th>$18,500.00</th></tr>
        </table>
        <p><strong>Desglose por forma de pago:</strong> Tarjeta $12,000 / PayPal $6,500</p>
    </div>

    <div class="card">
        <h3>Vista 2 — Detalle por curso</h3>
        <p><strong>PHP desde cero</strong></p>
        <table>
            <tr><th>Alumno</th><th>Inscripción</th><th>Avance</th><th>Precio pagado</th><th>Forma de pago</th></tr>
            <tr><td>Juan Pérez</td><td>10 Sep 2026</td><td>3/5</td><td>$1,200.00</td><td>Tarjeta</td></tr>
            <tr><td>María López</td><td>12 Sep 2026</td><td>5/5</td><td>$1,200.00</td><td>PayPal</td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Filtros</h3>
        <form method="get">
            <label>Rango de fechas de creación</label>
            <input type="date" name="fi"> <input type="date" name="ff">
            <label>Categoría</label>
            <select><option>Todas</option><option>IT &amp; Software</option></select>
            <label>Estado</label>
            <select><option>Todos</option><option>Activos</option></select>
            <p><button class="btn" type="submit">Filtrar</button></p>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>