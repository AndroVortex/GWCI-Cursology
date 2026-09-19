<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>Reporte de usuarios</h2>
    <div class="card">
        <form method="get">
            <label>Tipo de usuario</label>
            <select name="tipo">
                <option value="instructor">Instructor</option>
                <option value="estudiante">Estudiante</option>
            </select>
            <p><button class="btn" type="submit">Generar</button></p>
        </form>
    </div>

    <div class="card">
        <h3>Instructores</h3>
        <table>
            <tr><th>Usuario</th><th>Nombre</th><th>Fecha ingreso</th><th>Cursos ofrecidos</th><th>Total ganancias</th></tr>
            <tr><td>ana_dev</td><td>Ana Dev</td><td>01 Sep 2026</td><td>2</td><td>$18,500.00</td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Estudiantes</h3>
        <table>
            <tr><th>Usuario</th><th>Nombre</th><th>Fecha ingreso</th><th>Cursos inscritos</th><th>% terminados</th></tr>
            <tr><td>juan_perez</td><td>Juan Pérez</td><td>01 Sep 2026</td><td>3</td><td>33%</td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>