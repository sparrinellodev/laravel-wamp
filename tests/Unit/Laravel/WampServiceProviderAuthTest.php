<?php

use Hermod\LaravelWamp\Auth\AuthenticatorFactory;
use Hermod\LaravelWamp\Client\WampClientFactory;
use Hermod\LaravelWamp\Tests\TestCase;

uses(TestCase::class);

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
});
