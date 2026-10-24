<?php

use Hermod\LaravelWamp\Exceptions\InvalidMessageException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;

describe('WampMessage', function () {

    describe('fromArray()', function () {

        it('creates a message from a valid array', function () {
            $message = WampMessage::fromArray([2, 123456, []]);

            expect($message->type())->toBe(MessageType::WELCOME)
                ->and($message->get(1))->toBe(123456)
                ->and($message->get(2))->toBe([]);
        });

        it('throws for an empty array', function () {
            WampMessage::fromArray([]);
        })->throws(InvalidMessageException::class);

        it('throws for an unknown type', function () {
            WampMessage::fromArray([999, 'dato']);
        })->throws(InvalidMessageException::class, 'Unknown WAMP message type identifier: 999');

        it('returns null for a missing index', function () {
            $message = WampMessage::fromArray([2, 123456, []]);
            expect($message->get(99))->toBeNull();
        });
    });

    describe('create()', function () {

        it('creates a message with the correct parts', function () {
            $message = WampMessage::create(MessageType::CALL, 1, [], 'com.myapp.test', [42]);

            expect($message->type())->toBe(MessageType::CALL)
                ->and($message->toArray())->toBe([48, 1, [], 'com.myapp.test', [42]])
                ->and($message->get(0))->toBe(48)
                ->and($message->get(3))->toBe('com.myapp.test');
        });
    });

    describe('toArray()', function () {

        it('returns the full payload', function () {
            $data = [50, 1, [], [8]];
            $message = WampMessage::fromArray($data);

            expect($message->toArray())->toBe($data);
        });
    });
});
