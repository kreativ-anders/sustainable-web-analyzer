<?php

declare(strict_types=1);

use SustainableWebAnalyzer\Api;
use SustainableWebAnalyzer\Config;

// Development server (php -S … -t docs index.php): like nginx, only /api/ runs the analyzer, everything else is served from docs/.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (!preg_match('~^/api/?$~', $path)) {
        $file = realpath(__DIR__ . '/docs' . $path);
        if ($path === '/' || ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . '/docs/'))) {
            return false;
        }
        http_response_code(404); // the built-in server would answer unknown paths with index.html
        return true;
    }
}

require __DIR__ . '/src/bootstrap.php';

$config = Config::load(__DIR__);

set_time_limit((int) $config->get('max_execution_time') + 15);
ignore_user_abort(true); // finish and cache the analysis even if the visitor leaves

$api = new Api($config);
$api->handle($_SERVER, $_GET)->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD');

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$api->maintenance();
