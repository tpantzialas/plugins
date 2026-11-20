<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Η εγκατάσταση του Danae Mobile δεν έχει ρυθμιστεί ακόμη.';
    exit;
}
require $root . '/src/App.php';
\DanaeMobile\App::run(require $configFile, $root);
