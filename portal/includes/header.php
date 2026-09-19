<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cursologia</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f6f8; color: #222; }
        header { background: #0b2a4a; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 1.2rem; }
        nav a { color: #fff; margin-left: 14px; text-decoration: none; font-size: 0.9rem; }
        nav a:hover { text-decoration: underline; }
        .container { display: flex; min-height: 80vh; }
        aside { width: 220px; background: #123a63; color: #fff; padding: 16px; }
        aside h3 { margin-top: 0; font-size: 1rem; }
        aside a { display: block; color: #dbe9f7; text-decoration: none; padding: 6px 0; font-size: 0.9rem; }
        aside a:hover { color: #fff; }
        main { flex: 1; padding: 24px; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { border: 1px solid #ccd6e0; padding: 8px; text-align: left; font-size: 0.9rem; }
        th { background: #e8eef5; }
        .card { background: #fff; border: 1px solid #ccd6e0; padding: 16px; margin-bottom: 16px; border-radius: 6px; }
        .btn { display: inline-block; background: #0b6cf0; color: #fff; padding: 8px 14px; border-radius: 4px; text-decoration: none; font-size: 0.9rem; }
        .btn:hover { background: #0957c4; }
        .btn-danger { background: #c0392b; }
        .btn-secondary { background: #6c757d; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input, form select, form textarea { width: 100%; padding: 6px; margin-top: 4px; box-sizing: border-box; }
        .msg-error { color: #c0392b; font-size: 0.85rem; }
        .msg-ok { color: #1e8449; font-size: 0.85rem; }
    </style>
</head>
<body>
<header>
    <h1>Cursologia</h1>
    <nav>
        <a href="<?= url('index.php') ?>">Inicio</a>
        <a href="<?= url('busqueda.php') ?>">Buscar</a>
        <?php if (es_rol('publico')): ?>
            <a href="<?= url('login.php') ?>">Iniciar Sesión</a>
            <a href="<?= url('registro.php') ?>">Registrarse</a>
        <?php else: ?>
            <a href="<?= url('logout.php') ?>">Cerrar Sesión (<?= rol_actual() ?>)</a>
        <?php endif; ?>
    </nav>
</header>
<div class="container">