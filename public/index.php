<?php

declare(strict_types=1);

use SustainableWebAnalyzer\Api;
use SustainableWebAnalyzer\Config;

require dirname(__DIR__) . '/src/bootstrap.php';

$config = Config::load(dirname(__DIR__));

set_time_limit((int) $config->get('max_execution_time') + 15);
ignore_user_abort(true); // finish and cache the analysis even if the visitor leaves

$api = new Api($config);
$api->handle($_SERVER, $_GET)->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD');

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$api->maintenance();
