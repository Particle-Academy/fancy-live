<?php

declare(strict_types=1);

$testAutoload = getenv('FANCY_LIVE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (! is_file($testAutoload)) {
    throw new RuntimeException('Provide the approved PHPUnit toolchain through FANCY_LIVE_TEST_AUTOLOAD.');
}
require_once $testAutoload;
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'FancyLive\\')) {
        $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen('FancyLive\\'))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
