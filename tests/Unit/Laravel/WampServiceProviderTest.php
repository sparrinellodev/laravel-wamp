<?php

use Hermod\LaravelWamp\Client\WampClient;
use Hermod\LaravelWamp\Client\WampClientFactory;
use Hermod\LaravelWamp\Laravel\Facades\Wamp;
use Hermod\LaravelWamp\Serializer\SerializerFactory;
use Hermod\LaravelWamp\Tests\TestCase;

uses(TestCase::class);

describe('WampServiceProvider', function () {

    it('registers WampClientFactory in the container', function () {
        expect(app(WampClientFactory::class))
            ->toBeInstanceOf(WampClientFactory::class);
    });

    it('registers SerializerFactory in the container', function () {
        expect(app(SerializerFactory::class))
            ->toBeInstanceOf(SerializerFactory::class);
    });

    it('registers WampClient in the container', function () {
        expect(app(WampClient::class))
            ->toBeInstanceOf(WampClient::class);
    });

    it('registers the wamp.client alias', function () {
        expect(app('wamp.client'))
            ->toBeInstanceOf(WampClient::class);
    });

    it('resolves the Facade root', function () {
        expect(Wamp::getFacadeRoot())
            ->toBeInstanceOf(WampClient::class);
    });

    it('loads the default configuration', function () {
        expect(config('wamp.default'))->toBe('default')
            ->and(config('wamp.connections.default.url'))->toBe('ws://localhost:8080/ws')
            ->and(config('wamp.connections.default.realm'))->toBe('realm1');
    });

    it('registers the serializers in the config', function () {
        expect(config('wamp.serializers'))
            ->toHaveKeys(['json', 'msgpack', 'cbor']);
    });
});
