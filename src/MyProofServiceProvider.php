<?php

declare(strict_types=1);

namespace MyProof\LaravelClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class MyProofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/myproof-client.php', 'myproof-client');
        $this->app->singleton(MyProofClient::class, fn ($app) => new MyProofClient($app->make(Factory::class),
            (string) $app['config']->get('myproof-client.origin'), (string) $app['config']->get('myproof-client.token'),
            (int) $app['config']->get('myproof-client.timeout_seconds'), (int) $app['config']->get('myproof-client.connect_timeout_seconds'),
            (int) $app['config']->get('myproof-client.max_attempts')));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/myproof-client.php' => config_path('myproof-client.php')], 'myproof-client-config');
    }
}
