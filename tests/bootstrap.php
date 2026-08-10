<?php

$loader = require dirname(__DIR__, 3).'/vendor/autoload.php';

$loader->addPsr4('Azuriom\\Plugin\\Vouchers\\', dirname(__DIR__).'/src/');
$loader->addPsr4('Azuriom\\Plugin\\Vouchers\\Tests\\', __DIR__.'/');

$shopPath = dirname(__DIR__, 2).'/shop';

if (is_file($shopPath.'/composer.json')) {
    $loader->addPsr4('Azuriom\\Plugin\\Shop\\', $shopPath.'/src/');

    if (! function_exists('currency')) {
        require $shopPath.'/src/helpers.php';
    }
}
