<?php

$loader = require dirname(__DIR__, 3).'/vendor/autoload.php';

$loader->addPsr4('Azuriom\\Plugin\\Vouchers\\', dirname(__DIR__).'/src/');
$loader->addPsr4('Azuriom\\Plugin\\Vouchers\\Tests\\', __DIR__.'/');
