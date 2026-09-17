<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Sustainable Web Analyzer requires PHP 8.2 or newer.');
}

foreach (['curl', 'dom', 'libxml'] as $extension) {
    if (!extension_loaded($extension)) {
        http_response_code(500);
        exit("Sustainable Web Analyzer requires the PHP extension \"{$extension}\".");
    }
}

// Minimal PSR-4 autoloader: SustainableWebAnalyzer\Foo -> src/Foo.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'SustainableWebAnalyzer\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
