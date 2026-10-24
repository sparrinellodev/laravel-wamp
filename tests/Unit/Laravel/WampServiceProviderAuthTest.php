<?php

use Hermod\LaravelWamp\Auth\AuthenticatorFactory;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;
use Hermod\LaravelWamp\Client\WampClientFactory;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;

uses(TestCase::class);

/**
 * A provider with a constructor dependency: only the container can build it.
 */
class ConfigTicketProvider implements TicketProviderContract
{
    public function __construct(private readonly Repository $config) {}

    public function ticket(?string $authId, array $extra = []): string
    {
        return $this->config->get('services.wamp.token');
    }
}

describe('WampServiceProvider — Auth', function () {

    it('registers AuthenticatorFactory in the container', function () {
        expect(app(AuthenticatorFactory::class))
            ->toBeInstanceOf(AuthenticatorFactory::class);
    });

    it('injects AuthenticatorFactory into WampClientFactory', function () {
        $factory = app(WampClientFactory::class);

        expect($factory)->toBeInstanceOf(WampClientFactory::class);
    });

    it('uses anonymous auth in the default config', function () {
        expect(config('wamp.connections.default.auth.method'))
            ->toBe('anonymous');
    });

    it('enables reconnect in the default config', function () {
        expect(config('wamp.connections.default.reconnect.enabled'))
            ->toBeTrue();
    });

    it('enables heartbeat in the default config', function () {
        expect(config('wamp.connections.default.heartbeat.enabled'))
            ->toBeTrue();
    });

    it('reads the default connection credentials from the environment', function () {
        $env = [
            'WAMP_AUTH_METHOD' => 'ticket',
            'WAMP_AUTH_ID' => 'backend',
            'WAMP_AUTH_TICKET' => 'my-ticket',
            'WAMP_AUTH_SECRET' => 'my-secret',
        ];

        foreach ($env as $key => $value) {
            $_SERVER[$key] = $value;
        }

        try {
            // Load the package's own config file, not the one set up by TestCase
            $auth = (require __DIR__.'/../../../config/wamp.php')['connections']['default']['auth'];
        } finally {
            foreach (array_keys($env) as $key) {
                unset($_SERVER[$key]);
            }
        }

        expect($auth)->toBe([
            'method' => 'ticket',
            'authid' => 'backend',
            'ticket' => 'my-ticket',
            'secret' => 'my-secret',
        ])
            ->and(app(AuthenticatorFactory::class)->make($auth))->toBeInstanceOf(TicketAuthenticator::class);
    });

    it('resolves the ticket provider through the container', function () {
        config()->set('services.wamp.token', 'jwt-from-container');

        $auth = app(AuthenticatorFactory::class)->make([
            'method' => 'ticket',
            'authid' => 'backend',
            'ticket_provider' => ConfigTicketProvider::class,
        ]);

        expect($auth->handleChallenge(''))->toBe('jwt-from-container');
    });
});
