<?php

use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\MessageType;

describe('MessageFactory — PubSub', function () {

    // -------------------------------------------------------------------------
    // publish()
    // -------------------------------------------------------------------------

    describe('publish()', function () {

        it('builds a PUBLISH message without acknowledge', function () {
            $message = MessageFactory::publish(
                requestId: 1,
                topic: 'com.myapp.notifiche',
                args: ['ciao'],
            );

            expect($message->type())->toBe(MessageType::PUBLISH)
                ->and($message->get(1))->toBe(1)
                ->and($message->get(3))->toBe('com.myapp.notifiche')
                ->and($message->get(4))->toBe(['ciao']);

            // options must not contain acknowledge
            $options = $message->get(2);
            expect(isset($options->acknowledge))->toBeFalse();
        });

        it('builds a PUBLISH message with acknowledge=true', function () {
            $message = MessageFactory::publish(
                requestId: 1,
                topic: 'com.myapp.notifiche',
                args: [],
                kwargs: [],
                acknowledge: true,
            );

            expect($message->get(2))->toEqual((object) ['acknowledge' => true]);
        });

        it('includes kwargs in the message', function () {
            $message = MessageFactory::publish(
                requestId: 1,
                topic: 'com.myapp.test',
                args: [],
                kwargs: ['nome' => 'Mario'],
            );

            // kwargs is a stdClass when not empty
            expect($message->get(5))->not->toBeEmpty();
        });

        it('moves associative args into kwargs automatically', function () {
            $message = MessageFactory::publish(
                requestId: 1,
                topic: 'com.myapp.test',
                args: ['messaggio' => 'ciao!'],
            );

            // args must be an empty list
            expect($message->get(4))->toBe([]);

            // kwargs must contain the associative data
            $kwargs = (array) $message->get(5);
            expect($kwargs)->toBe(['messaggio' => 'ciao!']);
        });

        it('keeps a positional list in args', function () {
            $message = MessageFactory::publish(
                requestId: 1,
                topic: 'com.myapp.test',
                args: [1, 2, 3],
            );

            expect($message->get(4))->toBe([1, 2, 3]);
        });
    });

    // -------------------------------------------------------------------------
    // subscribe()
    // -------------------------------------------------------------------------

    describe('subscribe()', function () {

        it('builds a valid SUBSCRIBE message', function () {
            $message = MessageFactory::subscribe(42, 'com.myapp.notifiche');

            expect($message->type())->toBe(MessageType::SUBSCRIBE)
                ->and($message->get(1))->toBe(42)
                ->and($message->get(3))->toBe('com.myapp.notifiche');
        });

        it('encodes options as an empty object', function () {
            $message = MessageFactory::subscribe(1, 'com.myapp.test');
            $raw = json_encode($message->toArray());

            // options must be {}, not []
            expect($raw)->toContain('{}');
        });
    });

    // -------------------------------------------------------------------------
    // unsubscribe()
    // -------------------------------------------------------------------------

    describe('unsubscribe()', function () {

        it('builds a valid UNSUBSCRIBE message', function () {
            $message = MessageFactory::unsubscribe(99, 7777);

            expect($message->type())->toBe(MessageType::UNSUBSCRIBE)
                ->and($message->get(1))->toBe(99)
                ->and($message->get(2))->toBe(7777);
        });
    });
});
