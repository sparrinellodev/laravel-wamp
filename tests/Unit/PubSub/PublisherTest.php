<?php

use Amp\Future;
use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\PubSub\Publisher;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Session\WampSession;

describe('Publisher', function () {

    beforeEach(function () {
        $this->session = Mockery::mock(WampSession::class);
        $this->publisher = new Publisher($this->session, new RequestIdGenerator);
    });

    afterEach(fn () => Mockery::close());

    // -------------------------------------------------------------------------
    // publish()
    // -------------------------------------------------------------------------

    describe('publish()', function () {

        it('sends PUBLISH without the acknowledge option', function () {
            $this->session
                ->shouldReceive('send')
                ->once()
                ->withArgs(function (WampMessage $message) {
                    $options = $message->get(2);

                    return ! property_exists($options, 'acknowledge');
                });

            $this->publisher->publish('com.myapp.test', []);
        });

        it('sends associative data as kwargs in PUBLISH', function () {
            $this->session
                ->shouldReceive('send')
                ->once()
                ->withArgs(function (WampMessage $message) {
                    return $message->type() === MessageType::PUBLISH
                        && $message->get(3) === 'com.myapp.notifiche'
                        && $message->get(4) === []
                        && $message->get(5)->dato === 'valore';
                });

            $this->publisher->publish('com.myapp.notifiche', ['dato' => 'valore']);
        });

        it('sends PUBLISH with positional args', function () {
            $this->session
                ->shouldReceive('send')
                ->once()
                ->withArgs(function (WampMessage $message) {
                    return $message->get(4) === [1, 2, 3];
                });

            $this->publisher->publish('com.myapp.test', [1, 2, 3]);
        });
    });

    // -------------------------------------------------------------------------
    // publishWithAck()
    // -------------------------------------------------------------------------

    describe('publishWithAck()', function () {

        it('returns a Future', function () {
            $this->session->shouldReceive('send')->once();

            $future = $this->publisher->publishWithAck('com.myapp.test', []);

            expect($future)->toBeInstanceOf(Future::class);
        });

        it('sends PUBLISH with the acknowledge=true option', function () {
            $this->session
                ->shouldReceive('send')
                ->once()
                ->withArgs(function (WampMessage $message) {
                    $options = $message->get(2);

                    return ($options->acknowledge ?? false) === true;
                });

            $this->publisher->publishWithAck('com.myapp.test', []);
        });

        it('resolves the Future when PUBLISHED arrives', function () {
            $this->session->shouldReceive('send')->once();

            $future = $this->publisher->publishWithAck('com.myapp.test', []);

            // Retrieve the requestId of the sent message
            $requestId = null;
            $this->session
                ->shouldReceive('send')
                ->never(); // no second send expected

            // Simulate PUBLISHED from the router
            // [17, requestId, publicationId]
            // First we need to find the requestId
            $pendingAcks = (new ReflectionProperty($this->publisher, 'pendingAcks'))
                ->getValue($this->publisher);

            $requestId = array_key_first($pendingAcks);

            $published = WampMessage::fromArray([17, $requestId, 5555]);
            $this->publisher->onPublished($published);

            expect($future->await())->toBe(5555);
        });

        it('rejects the Future when ERROR for PUBLISH arrives', function () {
            $this->session->shouldReceive('send')->once();

            $future = $this->publisher->publishWithAck('com.myapp.test', []);

            $pendingAcks = (new ReflectionProperty($this->publisher, 'pendingAcks'))
                ->getValue($this->publisher);

            $requestId = array_key_first($pendingAcks);

            // [8, PUBLISH, requestId, {}, "wamp.error.not_authorized"]
            $error = WampMessage::fromArray([8, 16, $requestId, [], 'wamp.error.not_authorized']);
            $this->publisher->onError($error);

            expect(fn () => $future->await())
                ->toThrow(PubSubException::class);
        });

        it('ignores PUBLISHED for an unknown requestId', function () {
            $unknown = WampMessage::fromArray([17, 99999, 1234]);
            $this->publisher->onPublished($unknown);

            expect(true)->toBeTrue();
        });

        it('fails every pending acknowledgement with rejectAll()', function () {
            $this->session->shouldReceive('send')->once();

            $future = $this->publisher->publishWithAck('com.myapp.test', []);
            $this->publisher->rejectAll(new RuntimeException('Connection lost'));

            expect(fn () => $future->await())->toThrow(RuntimeException::class, 'Connection lost');
        });
    });
});
