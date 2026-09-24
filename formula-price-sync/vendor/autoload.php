<?php
// Minimal PSR-4 autoloader for FormulaPriceSync
spl_autoload_register(function ($class) {
    $prefix = 'FormulaPriceSync\\';
    $base_dir = dirname(__DIR__) . '/includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
