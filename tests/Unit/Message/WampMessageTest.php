<?php

use Hermod\LaravelWamp\Exceptions\InvalidMessageException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;

describe('WampMessage', function () {

    describe('fromArray()', function () {

        it('crea un messaggio da un array valido', function () {
            $message = WampMessage::fromArray([2, 123456, []]);

            expect($message->type())->toBe(MessageType::WELCOME)
                ->and($message->get(1))->toBe(123456)
                ->and($message->get(2))->toBe([]);
        });

        it('lancia eccezione per array vuoto', function () {
            WampMessage::fromArray([]);
        })->throws(InvalidMessageException::class);

        it('lancia eccezione per tipo sconosciuto', function () {
            WampMessage::fromArray([999, 'dato']);
        })->throws(InvalidMessageException::class, 'Unknown WAMP message type identifier: 999');

        it('restituisce null per indice inesistente', function () {
            $message = WampMessage::fromArray([2, 123456, []]);
            expect($message->get(99))->toBeNull();
        });
    });

    describe('create()', function () {

        it('crea un messaggio con i parti corrette', function () {
            $message = WampMessage::create(MessageType::CALL, 1, [], 'com.myapp.test', [42]);

            expect($message->type())->toBe(MessageType::CALL)
                ->and($message->toArray())->toBe([48, 1, [], 'com.myapp.test', [42]])
                ->and($message->get(0))->toBe(48)
                ->and($message->get(3))->toBe('com.myapp.test');
        });
    });

    describe('toArray()', function () {

        it('restituisce il payload completo', function () {
            $data = [50, 1, [], [8]];
            $message = WampMessage::fromArray($data);

            expect($message->toArray())->toBe($data);
        });
    });
});
