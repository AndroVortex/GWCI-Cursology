<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Detalle del curso';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main>
    <h2>PHP desde cero</h2>
    <div class="card">
        <p><strong>Categoría:</strong> IT &amp; Software</p>
        <p><strong>Instructor:</strong> ana_dev</p>
        <p><strong>Precio:</strong> $1,200.00 MXN</p>
        <p><strong>Promedio:</strong> 4.8 / 5</p>
        <p><strong>Descripción:</strong> Curso introductorio de PHP con POO y MVC.</p>
        <p><strong>Niveles:</strong> 5 (Nivel 1 gratis)</p>
        <a class="btn" href="login.php">Iniciar sesión para comprar</a>
    </div>

    <div class="card">
        <h3>Comentarios</h3>
        <p><em>ana_dev — 12 Sep 2026:</em> Excelente curso.</p>
        <p><em>luis_ux — 14 Sep 2026:</em> Muy claro.</p>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>