<?php

declare(strict_types=1);

use FancyLive\HttpResponse;
use FancyLive\Laravel\LiveBroadcaster;
use FancyLive\Laravel\LiveServiceProvider;
use FancyLive\Publisher;
use FancyLive\SignedRequest;
use FancyLive\Transport;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class LaravelProviderTest extends TestCase
{
    public function test_optional_provider_registers_the_driver_without_publishing_or_exposing_routes(): void
    {
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__));
        $app->instance('config', new Repository([
            'fancy-live' => ['endpoint' => 'https://socket.example', 'app_id' => '3', 'key' => 'fixture-key', 'secret' => 'public-fixture-secret'],
            'broadcasting' => ['default' => 'fancy-live'],
        ]));
        $transport = new class implements Transport
        {
            public array $requests = [];

            public function send(SignedRequest $request): HttpResponse
            {
                $this->requests[] = $request;

                return new HttpResponse(200);
            }
        };
        $app->instance(Transport::class, $transport);
        try {
            $routesBefore = count($app['router']->getRoutes());
            $provider = new LiveServiceProvider($app);
            $provider->register();
            $manager = new BroadcastManager($app);
            $provider->boot($manager);
            $this->assertSame([], $transport->requests);
            $this->assertSame($routesBefore, count($app['router']->getRoutes()));
            $app->make(Publisher::class)->publish(['room'], 'cms.updated', ['revision' => 'r0']);
            $this->assertCount(1, $transport->requests);
            $transport->requests = [];
            $driver = $manager->connection();
            $this->assertInstanceOf(LiveBroadcaster::class, $driver);
            $driver->broadcast(['room'], 'cms.updated', ['revision' => 'r1']);
            $this->assertCount(1, $transport->requests);
        } finally {
            $app->flush();
            Container::setInstance($previous);
        }
    }
}
