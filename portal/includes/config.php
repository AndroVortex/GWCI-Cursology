<?php
session_start();



if (!isset($_SESSION['rol'])) {
    $_SESSION['rol'] = 'publico'; // publico, estudiante, instructor, admin
}

function url($path = '') {
    return '/' . ltrim($path, '/');
}

function rol_actual() {
    return $_SESSION['rol'] ?? 'publico';
}

function es_rol($rol) {
    return rol_actual() === $rol;
}