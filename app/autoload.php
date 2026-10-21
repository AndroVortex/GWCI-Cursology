<?php

declare(strict_types=1);

// Los segmentos del namespace se buscan en carpetas en minúsculas (convención del
// repositorio: app/models, app/database...); el archivo conserva el nombre de la clase.
// App\Models\Entities\AppUser -> app/models/entities/AppUser.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $segments = explode('\\', substr($class, strlen($prefix)));
    $className = array_pop($segments);
    $directory = implode('/', array_map('strtolower', $segments));

    $file = __DIR__ . '/' . ($directory === '' ? '' : $directory . '/') . $className . '.php';
    if (is_file($file)) {
        require $file;
    }
});
