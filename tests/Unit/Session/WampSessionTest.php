<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Contracts\TransportContract;
use Hermod\LaravelWamp\Exceptions\SessionException;
use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Exceptions\WampProtocolException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Session\SessionState;
use Hermod\LaravelWamp\Session\WampSession;

describe('WampSession', function () {

    beforeEach(function () {
        $this->transport = Mockery::mock(TransportContract::class);
        $this->serializer = new JsonSerializer;
        $this->authenticator = new AnonymousAuthenticator;
        $this->session = new WampSession(
            $this->transport,
            $this->serializer,
            'realm1',
            $this->authenticator,
        );
    });

    afterEach(fn () => Mockery::close());

    it('starts in the Closed state', function () {
        expect($this->session->getState())->toBe(SessionState::Closed)
            ->and($this->session->isEstablished())->toBeFalse()
            ->and($this->session->getSessionId())->toBeNull();
    });

    it('establishes the session on WELCOME', function () {
        $welcome = json_encode([2, 987654, ['roles' => []]]);

        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->once();
        $this->transport->shouldReceive('receive')->once()->andReturn($welcome);

        $this->session->hello();

        expect($this->session->isEstablished())->toBeTrue()
            ->and($this->session->getSessionId())->toBe(987654)
            ->and($this->session->getRealm())->toBe('realm1')
            ->and($this->session->getState())->toBe(SessionState::Established);
    });

    it('throws WampProtocolException on ABORT', function () {
        $abort = json_encode([3, ['message' => 'Realm inesistente'], 'wamp.error.no_such_realm']);

        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->once();
        $this->transport->shouldReceive('receive')->once()->andReturn($abort);
        $this->transport->shouldReceive('close')->once();

        expect(fn () => $this->session->hello())
            ->toThrow(WampProtocolException::class, 'no_such_realm');
    });

    it('throws SessionException when hello() is called twice', function () {
        $welcome = json_encode([2, 111, []]);

        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->once();
        $this->transport->shouldReceive('receive')->once()->andReturn($welcome);

        $this->session->hello();

        expect(fn () => $this->session->hello())
            ->toThrow(SessionException::class, 'hello');
    });

    it('closes the session with goodbye()', function () {
        $welcome = json_encode([2, 111, []]);
        $goodbye = json_encode([6, [], 'wamp.close.normal']);

        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->twice();
        $this->transport->shouldReceive('receive')->andReturn($welcome, $goodbye);
        $this->transport->shouldReceive('close')->once();

        $this->session->hello();
        $this->session->goodbye();

        expect($this->session->isEstablished())->toBeFalse()
            ->and($this->session->getState())->toBe(SessionState::Closed)
            ->and($this->session->getSessionId())->toBeNull();
    });

    it('throws SessionException when send() is called without an active session', function () {
        $message = WampMessage::create(MessageType::CALL, 1, [], 'com.test', []);

        expect(fn () => $this->session->send($message))
            ->toThrow(SessionException::class, 'send');
    });

    // -------------------------------------------------------------------------
    // Connection loss
    // -------------------------------------------------------------------------

    it('closes the session when the transport fails on receive()', function () {
        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->once();
        $this->transport->shouldReceive('receive')->twice()->andReturnUsing(
            fn () => json_encode([2, 111, []]),
            fn () => throw new TransportException('Connection reset by peer'),
        );
        $this->transport->shouldReceive('close')->once();

        $this->session->hello();

        expect(fn () => $this->session->receive())->toThrow(TransportException::class)
            ->and($this->session->getState())->toBe(SessionState::Closed)
            ->and($this->session->getSessionId())->toBeNull();
    });

    it('closes the session when the transport fails on send()', function () {
        $this->transport->shouldReceive('connect')->once();
        $this->transport->shouldReceive('send')->twice()->andReturnUsing(
            fn () => null,
            fn () => throw new TransportException('Broken pipe'),
        );
        $this->transport->shouldReceive('receive')->once()->andReturn(json_encode([2, 111, []]));
        $this->transport->shouldReceive('close')->once();

        $this->session->hello();
        $message = WampMessage::create(MessageType::CALL, 1, [], 'com.test', []);

        expect(fn () => $this->session->send($message))->toThrow(TransportException::class)
            ->and($this->session->getState())->toBe(SessionState::Closed);
    });

    it('leaves the session Closed when the handshake fails, so hello() can be retried', function () {
        $this->transport->shouldReceive('connect')->twice();
        $this->transport->shouldReceive('send')->twice();
        $this->transport->shouldReceive('receive')->twice()->andReturnUsing(
            fn () => throw new TransportException('Connection reset by peer'),
            fn () => json_encode([2, 222, []]),
        );
        $this->transport->shouldReceive('close')->once();

        expect(fn () => $this->session->hello())->toThrow(TransportException::class)
            ->and($this->session->getState())->toBe(SessionState::Closed);

        $this->session->hello();

        expect($this->session->getSessionId())->toBe(222);
    });
});
