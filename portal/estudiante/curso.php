<?php
require_once __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h2>PHP desde cero</h2>
    <div class="card">
        <p><strong>Avance:</strong> 3 de 5 niveles</p>
        <p><strong>Certificado:</strong> Pendiente</p>
    </div>
    <div class="card">
        <h3>Niveles</h3>
        <table>
            <tr><th>#</th><th>Título</th><th>Estado</th><th></th></tr>
            <tr><td>1</td><td>Introducción</td><td>Completado</td><td><a class="btn" href="nivel.php?id=1">Ver</a></td></tr>
            <tr><td>2</td><td>Sintaxis</td><td>Completado</td><td><a class="btn" href="nivel.php?id=2">Ver</a></td></tr>
            <tr><td>3</td><td>POO</td><td>Completado</td><td><a class="btn" href="nivel.php?id=3">Ver</a></td></tr>
            <tr><td>4</td><td>MVC</td><td>Pendiente</td><td><a class="btn" href="nivel.php?id=4">Ver</a></td></tr>
            <tr><td>5</td><td>Proyecto final</td><td>Pendiente</td><td><a class="btn" href="nivel.php?id=5">Ver</a></td></tr>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>