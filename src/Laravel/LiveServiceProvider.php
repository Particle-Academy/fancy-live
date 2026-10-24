<?php

declare(strict_types=1);

namespace FancyLive\Laravel;

use FancyLive\Authorization;
use FancyLive\Config;
use FancyLive\LiveFailure;
use FancyLive\NativeHttpTransport;
use FancyLive\Publisher;
use FancyLive\Transport;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;

final class LiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fancy-live.php', 'fancy-live');
        $this->app->bindIf(Transport::class, NativeHttpTransport::class);
        $this->app->bindIf(Config::class, static fn ($app): Config => self::configuration($app['config']->get('fancy-live', [])));
        $this->app->bindIf(Publisher::class, static fn ($app): Publisher => new Publisher($app->make(Config::class), $app->make(Transport::class)));
        $this->app->bindIf(Authorization::class, static fn ($app): Authorization => new Authorization($app->make(Config::class)));
        if ($this->app['config']->get('broadcasting.connections.fancy-live') === null) {
            $this->app['config']->set('broadcasting.connections.fancy-live', ['driver' => 'fancy-live']);
        }
    }

    public function boot(BroadcastManager $manager): void
    {
        // Laravel rebinds driver callbacks to the manager's class scope.
        $configure = self::configuration(...);
        $manager->extend('fancy-live', static function ($app, array $options) use ($configure): LiveBroadcaster {
            $config = $configure(array_replace($app['config']->get('fancy-live', []), $options));

            return new LiveBroadcaster(new Publisher($config, $app->make(Transport::class)), new Authorization($config));
        });
    }

    private static function configuration(array $options): Config
    {
        foreach (['endpoint', 'app_id', 'key', 'secret'] as $key) {
            if (! isset($options[$key]) || (! is_string($options[$key]) && ! ($key === 'app_id' && is_int($options[$key])))) {
                throw new LiveFailure('invalid_configuration');
            }
        }
        $timeout = $options['timeout'] ?? 3.0;
        if (! is_numeric($timeout)) {
            throw new LiveFailure('invalid_configuration');
        }

        return new Config($options['endpoint'], (string) $options['app_id'], $options['key'], $options['secret'], (float) $timeout);
    }
}
