<?php

declare(strict_types=1);
use PHPUnit\TextUI\Application;

require __DIR__.'/bootstrap.php';
if (! class_exists(Application::class)) {
    throw new RuntimeException('The package test suite requires the approved PHPUnit development toolchain.');
}
exit((new Application)->run([__FILE__, '--configuration', dirname(__DIR__).'/phpunit.xml', ...array_slice($_SERVER['argv'], 1)]));
