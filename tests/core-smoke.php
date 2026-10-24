<?php

declare(strict_types=1);
use FancyLive\Config;
use FancyLive\HttpResponse;
use FancyLive\Publisher;
use FancyLive\SignedRequest;
use FancyLive\Transport;
use Illuminate\Support\ServiceProvider;

// Run in a fresh PHP process, with no Composer or framework autoloader.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'FancyLive\\')) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen('FancyLive\\'))).'.php';
    }
});
if (class_exists(ServiceProvider::class, false)) {
    throw new RuntimeException('The core smoke must not preload Laravel.');
}
$transport = new class implements Transport
{
    public int $calls = 0;

    public function send(SignedRequest $request): HttpResponse
    {
        $this->calls++;

        return new HttpResponse(200);
    }
};
$config = new Config('https://socket.example', '3', 'fixture-key', 'public-fixture-secret');
(new Publisher($config, $transport))->publish(['room'], 'cms.updated', ['revision' => 'r1']);
if ($transport->calls !== 1 || class_exists(ServiceProvider::class, false)) {
    throw new RuntimeException('The core must work without Laravel.');
}
echo "PASS: core publishes through its transport with no Composer/framework autoloader.\n";
