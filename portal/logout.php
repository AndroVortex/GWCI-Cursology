<?php
require_once __DIR__ . '/includes/config.php';
$_SESSION['rol'] = 'publico';
header('Location: index.php');
exit;