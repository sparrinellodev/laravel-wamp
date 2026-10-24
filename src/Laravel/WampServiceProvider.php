<?php

namespace Hermod\LaravelWamp\Laravel;

use Hermod\LaravelWamp\Auth\AuthenticatorFactory;
use Hermod\LaravelWamp\Client\WampClient;
use Hermod\LaravelWamp\Client\WampClientFactory;
use Hermod\LaravelWamp\Laravel\Console\WampCallCommand;
use Hermod\LaravelWamp\Laravel\Console\WampServeCommand;
use Hermod\LaravelWamp\Serializer\SerializerFactory;
use Hermod\LaravelWamp\Session\WampSessionFactory;
use Hermod\LaravelWamp\Transport\RawSocketTransportFactory;
use Hermod\LaravelWamp\Transport\TransportFactory;
use Hermod\LaravelWamp\Transport\WebSocketTransportFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel Service Provider for the Laravel-Wamp package.
 *
 * Handles bootstrapping configuration publishing, registering dependency injection 
 * singletons for factories, core clients, and registering Artisan console commands.
 */
class WampServiceProvider extends ServiceProvider
{
    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->publishConfig();
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    /**
     * Register any package bindings into the Laravel service container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/wamp.php',
            'wamp',
        );

        $this->registerFactories();
        $this->registerClient();
        $this->registerCommands();
    }

    // -------------------------------------------------------------------------
    // Configuration Publishing
    // -------------------------------------------------------------------------

    /**
     * Publish configuration files when running in the console.
     */
    private function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/wamp.php' => config_path('wamp.php'),
            ], 'wamp-config');
        }
    }

    // -------------------------------------------------------------------------
    // Factories Registration
    // -------------------------------------------------------------------------

    /**
     * Register factory singletons in the service container.
     */
    private function registerFactories(): void
    {
        $this->app->singleton(SerializerFactory::class, function ($app) {
            return new SerializerFactory(
                $app['config']->get('wamp.serializers', []),
            );
        });

        $this->app->singleton(WebSocketTransportFactory::class, function () {
            return new WebSocketTransportFactory;
        });

        $this->app->singleton(RawSocketTransportFactory::class, function () {
            return new RawSocketTransportFactory;
        });

        $this->app->singleton(TransportFactory::class, function ($app) {
            return new TransportFactory(
                websocketFactory: $app->make(WebSocketTransportFactory::class),
                rawSocketFactory: $app->make(RawSocketTransportFactory::class),
            );
        });

        $this->app->singleton(WampSessionFactory::class, function () {
            return new WampSessionFactory;
        });

        $this->app->singleton(AuthenticatorFactory::class, function ($app) {
            return new AuthenticatorFactory(
                // Ticket providers are resolved from the container, so they can use dependency injection
                resolver: fn (string $class) => $app->make($class),
            );
        });

        $this->app->singleton(WampClientFactory::class, function ($app) {
            return new WampClientFactory(
                serializerFactory: $app->make(SerializerFactory::class),
                transportFactory: $app->make(TransportFactory::class),
                sessionFactory: $app->make(WampSessionFactory::class),
                authenticatorFactory: $app->make(AuthenticatorFactory::class),
            );
        });
    }

    // -------------------------------------------------------------------------
    // Client Registration
    // -------------------------------------------------------------------------

    /**
     * Register the primary WampClient binding and alias.
     *
     * @throws \RuntimeException If the default connection configuration cannot be found.
     */
    private function registerClient(): void
    {
        // Main binding — utilizes the 'default' connection configuration
        $this->app->singleton(WampClient::class, function ($app) {
            $connectionName = $app['config']->get('wamp.default', 'default');
            $config = $app['config']->get("wamp.connections.{$connectionName}");

            if (empty($config)) {
                throw new \RuntimeException(
                    "Laravel-Wamp configuration not found for connection '{$connectionName}'.",
                );
            }

            return $app->make(WampClientFactory::class)->make($config);
        });

        // String alias for Facade support
        $this->app->alias(WampClient::class, 'wamp.client');
    }

    // -------------------------------------------------------------------------
    // Artisan Commands Registration
    // -------------------------------------------------------------------------

    /**
     * Register package Artisan commands when running in console mode.
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WampServeCommand::class,
                WampCallCommand::class,
            ]);
        }
    }
}