<?php require_once __DIR__ . '/config.php'; ?>
<aside>
    <h3>Menú</h3>
    <?php if (es_rol('publico')): ?>
        <a href="<?= url('index.php') ?>">Página principal</a>
        <a href="<?= url('busqueda.php') ?>">Buscador</a>
        <a href="<?= url('login.php') ?>">Iniciar sesión</a>
        <a href="<?= url('registro.php') ?>">Registro</a>
    <?php elseif (es_rol('estudiante')): ?>
        <a href="<?= url('estudiante/dashboard.php') ?>">Panel estudiante</a>
        <a href="<?= url('estudiante/kardex.php') ?>">Kardex</a>
        <a href="<?= url('busqueda.php') ?>">Buscar cursos</a>
        <a href="<?= url('estudiante/mensajes.php') ?>">Mensajes</a>
        <a href="<?= url('estudiante/perfil.php') ?>">Mi perfil</a>
    <?php elseif (es_rol('instructor')): ?>
        <a href="<?= url('instructor/dashboard.php') ?>">Panel instructor</a>
        <a href="<?= url('instructor/mis-cursos.php') ?>">Mis cursos</a>
        <a href="<?= url('instructor/crear-curso.php') ?>">Crear curso</a>
        <a href="<?= url('instructor/ventas.php') ?>">Ventas</a>
        <a href="<?= url('instructor/mensajes.php') ?>">Mensajes</a>
        <a href="<?= url('instructor/perfil.php') ?>">Mi perfil</a>
    <?php elseif (es_rol('admin')): ?>
        <a href="<?= url('admin/dashboard.php') ?>">Panel admin</a>
        <a href="<?= url('admin/categorias.php') ?>">Categorías</a>
        <a href="<?= url('admin/usuarios.php') ?>">Usuarios</a>
        <a href="<?= url('admin/comentarios.php') ?>">Comentarios</a>
        <a href="<?= url('admin/reportes.php') ?>">Reportes</a>
        <a href="<?= url('admin/perfil.php') ?>">Mi perfil</a>
    <?php endif; ?>
</aside>