<?php
$viewsDir = realpath(dirname(__DIR__) . '/app/views');
$path = $_GET['path'] ?? '';
$file = is_string($path) ? realpath($viewsDir . '/' . $path) : false;

$types = [
    'css' => 'text/css; charset=UTF-8',
    'js'  => 'text/javascript; charset=UTF-8',
];
$extension = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';

if (
    $file === false
    || !str_starts_with($file, $viewsDir . DIRECTORY_SEPARATOR)
    || !isset($types[$extension])
    || !is_file($file)
) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $types[$extension]);
header('Cache-Control: no-cache');
readfile($file);
