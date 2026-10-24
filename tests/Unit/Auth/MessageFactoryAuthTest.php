<?php

use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\MessageType;

describe('MessageFactory — Auth', function () {

    // -------------------------------------------------------------------------
    // helloWithAuth()
    // -------------------------------------------------------------------------

    describe('helloWithAuth()', function () {

        it('builds HELLO with anonymous auth', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'anonymous',
            );

            expect($message->type())->toBe(MessageType::HELLO)
                ->and($message->get(1))->toBe('realm1')
                ->and($message->get(2)['authmethods'])->toBe(['anonymous']);
        });

        it('includes authid when provided', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'ticket',
                authId: 'user123',
            );

            expect($message->get(2)['authid'])->toBe('user123');
        });

        it('omits authid when null', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'anonymous',
                authId: null,
            );

            expect(isset($message->get(2)['authid']))->toBeFalse();
        });

        it('includes authextra when provided', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'wampcra',
                authId: 'user123',
                authExtra: ['channel_binding' => null],
            );

            expect($message->get(2)['authextra'])->toBe(['channel_binding' => null]);
        });

        it('encodes authextra as an empty object when not provided', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'anonymous',
            );

            $raw = json_encode($message->toArray());
            expect($raw)->toContain('"authextra":{}');
        });

        it('always includes roles', function () {
            $message = MessageFactory::helloWithAuth(
                realm: 'realm1',
                authMethod: 'anonymous',
            );

            expect($message->get(2)['roles'])
                ->toHaveKeys(['caller', 'callee']);
        });
    });

    // -------------------------------------------------------------------------
    // authenticate()
    // -------------------------------------------------------------------------

    describe('authenticate()', function () {

        it('builds a valid AUTHENTICATE message', function () {
            $message = MessageFactory::authenticate('my-signature');

            expect($message->type())->toBe(MessageType::AUTHENTICATE)
                ->and($message->get(1))->toBe('my-signature');
        });

        it('encodes extra as an empty object when not provided', function () {
            $message = MessageFactory::authenticate('sig');
            $raw = json_encode($message->toArray());

            expect($raw)->toContain('{}');
        });

        it('includes extra when provided', function () {
            $message = MessageFactory::authenticate('sig', ['channel_binding' => 'tls-unique']);

            expect($message->get(2))->toBe(['channel_binding' => 'tls-unique']);
        });
    });
});
